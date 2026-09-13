<?php

namespace Tests\Feature\Security;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "NO, SECURE MY ACCOUNT" link in the new-device email locks the account
 * into Protected Mode and signs every device out, without being logged in.
 * It used to be a bare HMAC of the device id with no expiry: anyone who ever
 * held one of those emails could freeze the account at will, forever. It is
 * now a temporary signed url, so it expires and cannot be forged or reused
 * past its window.
 */
class CompromiseLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: TrustedDevice} */
    private function userWithDevice(): array
    {
        $user = User::factory()->create();

        $device = TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', 'intruder'),
            'user_agent' => 'Intruder/1.0',
            'os' => 'Windows',
            'browser' => 'Edge',
            'ip_address' => '198.51.100.7',
            'trusted_since' => now(),
            'trust_score' => 40,
        ]);

        return [$user, $device];
    }

    public function test_a_valid_signed_link_locks_the_account_and_signs_every_device_out(): void
    {
        [$user, $device] = $this->userWithDevice();

        $url = URL::temporarySignedRoute('security.compromise', now()->addHours(48), ['device' => $device->id]);

        $this->get($url)
            ->assertOk()
            ->assertViewIs('auth.compromise-acknowledged');

        $user->refresh();
        $this->assertNotNull($user->restricted_at, 'the account must enter Protected Mode');
        $this->assertSame(1, $user->session_version, 'every session must be signed out');
        $this->assertNull($device->fresh()->trusted_since, 'device trust must be withdrawn');
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event_type' => 'Account Locked']);
    }

    public function test_a_tampered_link_is_refused(): void
    {
        [$user, $device] = $this->userWithDevice();

        $url = URL::temporarySignedRoute('security.compromise', now()->addHours(48), ['device' => $device->id]);

        // Point the same signature at a different device id.
        $this->get(str_replace('device='.$device->id, 'device='.($device->id + 1), $url))->assertStatus(403);

        $this->assertNull($user->fresh()->restricted_at);
    }

    public function test_an_expired_link_is_refused(): void
    {
        [$user, $device] = $this->userWithDevice();

        $url = URL::temporarySignedRoute('security.compromise', now()->addHours(48), ['device' => $device->id]);

        $this->travel(49)->hours();

        $this->get($url)->assertStatus(403);

        $this->assertNull($user->fresh()->restricted_at);
    }

    public function test_the_old_hmac_style_link_no_longer_works(): void
    {
        [$user, $device] = $this->userWithDevice();

        $legacy = route('security.compromise', [
            'device' => $device->id,
            'token' => hash_hmac('sha256', (string) $device->id, config('app.key')),
        ]);

        $this->get($legacy)->assertStatus(403);

        $this->assertNull($user->fresh()->restricted_at);
    }
}
