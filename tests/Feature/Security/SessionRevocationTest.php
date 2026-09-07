<?php

namespace Tests\Feature\Security;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Auth\Services\AccountClosureService;
use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\AccountBannedMail;
use App\Domain\Communication\Mail\Security\AccountSuspendedMail;
use App\Domain\Communication\Mail\Security\DeviceRemovedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Security\Services\SessionRevocationService;
use App\Models\Admin;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * Ending a customer's sessions must actually end them, on every session
 * driver. The old implementation deleted rows from the `sessions` table,
 * which production (redis) silently ignored: banned customers kept shopping,
 * "revoked" devices stayed signed in, and a thief survived the victim
 * changing their password.
 *
 * Two mechanisms are pinned here: recorded sessions are destroyed through
 * the session handler, and a per-user session_version lets the middleware
 * refuse any session that predates a "sign out everywhere", whether or not
 * it was ever recorded.
 */
class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<int, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CommunicationManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->andReturnUsing(function (...$args): void {
                $this->sent[] = $args;
            });
        });
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => uniqid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    /**
     * A trusted device with one recorded session, backed by a real row in
     * the `sessions` table so the database-driver destroy path is observable.
     *
     * @return array{0: TrustedDevice, 1: UserSession}
     */
    private function recordedSession(User $user, string $sessionId, ?\DateTimeInterface $trustedSince = null): array
    {
        $device = TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', $sessionId),
            'user_agent' => 'OtherBrowser/1.0',
            'os' => 'Android',
            'browser' => 'Chrome',
            'ip_address' => '203.0.113.9',
            'trusted_since' => $trustedSince ?? now()->subWeek(),
            'trust_score' => 40,
            'current_session_count' => 1,
        ]);

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => time(),
        ]);

        $session = UserSession::create([
            'user_id' => $user->id,
            'trusted_device_id' => $device->id,
            'laravel_session_id' => $sessionId,
            'access_token' => Str::random(60),
            'refresh_token' => Str::random(60),
            'risk_level' => 'LOW',
            'expires_at' => now()->addDays(30),
        ]);

        return [$device, $session];
    }

    private function mailOfType(string $class): ?array
    {
        return collect($this->sent)->first(fn (array $args) => ($args[4] ?? null) instanceof $class);
    }

    public function test_login_stamps_the_session_with_the_users_current_version(): void
    {
        Location::shouldReceive('get')->andReturn(false);
        $this->mock(RiskEngine::class)->shouldReceive('evaluateLoginRisk')->andReturn('LOW');

        $user = User::factory()->create(['session_version' => 3]);

        event(new Login('web', $user, false));

        $this->assertSame(3, session('auth.session_version'));
    }

    public function test_a_session_older_than_the_users_version_is_refused_on_the_web(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.session_version' => 0])
            ->get(route('dashboard'))
            ->assertOk();

        app(SessionRevocationService::class)->revokeAll($user);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_login_after_the_revocation_carries_the_new_version_and_is_accepted(): void
    {
        $user = User::factory()->create();

        app(SessionRevocationService::class)->revokeAll($user);

        $this->actingAs($user)->withSession(['auth.session_version' => 1])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_api_routes_refuse_revoked_and_banned_sessions_with_json(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.session_version' => 0])
            ->getJson(route('api.dashboard'))
            ->assertOk();

        app(SessionRevocationService::class)->revokeAll($user);

        $this->getJson(route('api.dashboard'))
            ->assertStatus(403)
            ->assertJsonPath('message', 'You were signed out of this device for security reasons. Please sign in again.');

        $banned = User::factory()->create(['banned_at' => now()]);

        $this->actingAs($banned)->withSession(['auth.session_version' => 0])
            ->getJson(route('api.dashboard'))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your account has been banned. Please contact support.');
    }

    public function test_revoke_all_destroys_recorded_and_unrecorded_sessions_on_the_database_driver(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['remember_token' => 'old-token']);
        [$device, $recorded] = $this->recordedSession($user, 'sess-recorded');

        // A session the overlay never saw (a remember-me revival, say).
        DB::table('sessions')->insert([
            'id' => 'sess-orphan',
            'user_id' => $user->id,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => time(),
        ]);

        app(SessionRevocationService::class)->revokeAll($user);

        $this->assertDatabaseMissing('sessions', ['id' => 'sess-recorded']);
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-orphan']);
        $this->assertNotNull($recorded->fresh()->revoked_at);
        $this->assertSame(0, (int) $device->fresh()->current_session_count);

        $user->refresh();
        $this->assertSame(1, $user->session_version);
        $this->assertNotSame('old-token', $user->remember_token);
    }

    public function test_revoking_a_device_signs_it_out_untrusts_it_and_tells_the_owner(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        [$device, $recorded] = $this->recordedSession($user, 'sess-stolen-phone');

        app(SessionRevocationService::class)->revokeDevice($device);

        $this->assertDatabaseMissing('sessions', ['id' => 'sess-stolen-phone']);
        $this->assertNotNull($recorded->fresh()->revoked_at);
        $device->refresh();
        $this->assertNull($device->trusted_since);
        $this->assertSame(0, (int) $device->trust_score);

        $notice = $this->mailOfType(DeviceRemovedMail::class);
        $this->assertNotNull($notice, 'the owner must be told a device was removed');
        $this->assertSame(CommunicationCategory::DeviceRemoved, $notice[1]);
        $this->assertSame('Android · Chrome', $notice[4]->deviceLabel);
    }

    public function test_banning_signs_the_customer_out_everywhere_and_notifies_them(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-token']);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.ban', $user))
            ->assertRedirect()
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertNotNull($user->banned_at);
        $this->assertSame(1, $user->session_version);
        $this->assertNotSame('old-token', $user->remember_token);
        $this->assertNotNull($this->mailOfType(AccountBannedMail::class));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.sessions_revoked']);
    }

    public function test_unbanning_does_not_touch_sessions_or_send_mail(): void
    {
        $user = User::factory()->create(['banned_at' => now(), 'session_version' => 2]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.ban', $user))
            ->assertRedirect();

        $this->assertNull($user->fresh()->banned_at);
        $this->assertSame(2, $user->fresh()->session_version);
        $this->assertSame([], $this->sent);
    }

    public function test_suspending_notifies_the_customer_with_the_reason(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.suspend', $user), ['reason' => 'Chargeback dispute'])
            ->assertRedirect();

        $notice = $this->mailOfType(AccountSuspendedMail::class);
        $this->assertNotNull($notice);
        $this->assertSame('Chargeback dispute', $notice[4]->reason);
        $this->assertSame(CommunicationCategory::AccountRestriction, $notice[1]);
    }

    public function test_security_centre_revoke_needs_the_password_and_then_works_even_from_a_new_device(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        [$stolen] = $this->recordedSession($user, 'sess-stolen');

        $this->actingAs($user);

        // The customer is on a device only minutes old: the maturity hold
        // would have refused the revoke for 24 hours. The password is enough.
        $current = TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'new-laptop'),
            'user_agent' => 'NewLaptop/1.0',
            'trusted_since' => now(),
            'trust_score' => 40,
        ]);
        UserSession::create([
            'user_id' => $user->id,
            'trusted_device_id' => $current->id,
            'laravel_session_id' => session()->getId(),
            'access_token' => Str::random(60),
            'refresh_token' => Str::random(60),
            'risk_level' => 'LOW',
            'expires_at' => now()->addDays(30),
        ]);

        Volt::test('settings.security')
            ->set('revoke_password', '')
            ->call('revokeDevice', $stolen->id)
            ->assertHasErrors(['revoke_password']);

        $this->assertNotNull($stolen->fresh()->trusted_since, 'no revoke without the password');

        Volt::test('settings.security')
            ->set('revoke_password', 'wrong-password')
            ->call('revokeDevice', $stolen->id)
            ->assertHasErrors(['revoke_password']);

        Volt::test('settings.security')
            ->set('revoke_password', 'password')
            ->call('revokeDevice', $stolen->id)
            ->assertHasNoErrors();

        $this->assertNull($stolen->fresh()->trusted_since);
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-stolen']);
        $this->assertNotNull($this->mailOfType(DeviceRemovedMail::class));
    }

    public function test_api_revoke_needs_the_password_and_then_works(): void
    {
        $user = User::factory()->create();
        [$stolen] = $this->recordedSession($user, 'sess-api-stolen');

        $this->actingAs($user)->withSession(['auth.session_version' => 0])
            ->deleteJson(route('api.security.devices.revoke', $stolen))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->deleteJson(route('api.security.devices.revoke', $stolen), ['password' => 'password'])
            ->assertOk();

        $this->assertNull($stolen->fresh()->trusted_since);
    }

    public function test_api_revoke_refuses_another_customers_device(): void
    {
        $user = User::factory()->create();
        [$theirs] = $this->recordedSession(User::factory()->create(), 'sess-someone-else');

        $this->actingAs($user)->withSession(['auth.session_version' => 0])
            ->deleteJson(route('api.security.devices.revoke', $theirs), ['password' => 'password'])
            ->assertStatus(403);

        $this->assertNotNull($theirs->fresh()->trusted_since);
    }

    public function test_changing_the_password_signs_every_other_session_out(): void
    {
        $user = User::factory()->create();

        // This session signs in and AuthenticateSession remembers the hash
        // it signed in under.
        $this->actingAs($user)->withSession(['auth.session_version' => 0])
            ->get(route('dashboard'))
            ->assertOk();

        // Another device changes the password.
        $user->forceFill(['password' => 'a-brand-new-secret'])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_requesting_account_closure_signs_out_every_device(): void
    {
        $user = User::factory()->create();

        app(AccountClosureService::class)->request($user);

        $this->assertSame(1, $user->fresh()->session_version);
    }
}
