<?php

namespace Tests\Feature\Compliance;

use App\Domain\Communication\Mail\Security\DataExportedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Shared\Enums\Currency;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The right of access: a customer can download a PDF copy of their data
 * after proving control of the account's email with a one-time code, with
 * every download recorded so an access request has a fulfilment timestamp.
 */
class DataExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Event::fake([OtpRequested::class]);

        $this->mock(CommunicationManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->andReturnUsing(function (...$args): void {
                $this->sent[] = $args;
            });
        });
    }

    /** @var array<int, array<int, mixed>> */
    private array $sent = [];

    /**
     * Visit the page (which emails a code) and hand back the code that was sent.
     */
    private function requestCode(User $user): string
    {
        $this->actingAs($user)->get(route('privacy.export'))->assertOk()->assertSee('Download your data');

        $otp = null;
        Event::assertDispatched(OtpRequested::class, function (OtpRequested $event) use (&$otp, $user) {
            $otp = $event->otp;

            return $event->user->is($user) && $event->context === 'data_export';
        });

        return $otp;
    }

    public function test_the_right_code_downloads_a_pdf_and_is_audited(): void
    {
        $user = User::factory()->create(['name' => 'Ada Okafor', 'email' => 'ada@example.test']);
        Wallet::create(['user_id' => $user->id, 'currency' => Currency::USD, 'balance' => 12.5]);
        Order::factory()->create(['user_id' => $user->id, 'order_status' => 'completed']);

        $otp = $this->requestCode($user);

        // Verification lands back on settings with a confirmation, and the
        // page then fetches the file itself.
        $this->actingAs($user)
            ->post(route('privacy.export.verify'), ['otp' => $otp])
            ->assertRedirect(route('dashboard.profile'))
            ->assertSessionHas('status', 'Verified. Your data is downloading.')
            ->assertSessionHas('data_export_ready', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.data_exported', 'actor_id' => $user->id]);

        // A security notice goes to the account email; the file itself never does.
        $notice = collect($this->sent)->first(fn (array $args) => ($args[4] ?? null) instanceof DataExportedMail);
        $this->assertNotNull($notice);
        $this->assertSame('Ada Okafor', $notice[4]->name);

        $response = $this->actingAs($user)->get(route('privacy.export.file'));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        // The file ticket and the code are both single-use.
        $this->actingAs($user)->get(route('privacy.export.file'))->assertRedirect(route('privacy.export'));
        $this->actingAs($user)
            ->post(route('privacy.export.verify'), ['otp' => $otp])
            ->assertSessionHasErrors('otp');
    }

    public function test_the_page_masks_the_email_and_a_wrong_code_downloads_nothing(): void
    {
        $user = User::factory()->create(['email' => 'adaeze@example.test']);

        $this->requestCode($user);

        $this->actingAs($user)->get(route('privacy.export'))->assertSee('ad****@example.test');

        $this->actingAs($user)
            ->post(route('privacy.export.verify'), ['otp' => '000000'])
            ->assertSessionHasErrors('otp');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.data_exported']);
    }

    public function test_five_wrong_guesses_burn_the_code(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestCode($user);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('privacy.export.verify'), ['otp' => '111111']);
        }

        // Even the right code no longer works: a fresh one must be requested.
        $this->actingAs($user)
            ->post(route('privacy.export.verify'), ['otp' => $otp])
            ->assertSessionHasErrors('otp');
    }

    public function test_resending_too_soon_is_refused(): void
    {
        $user = User::factory()->create();
        $this->requestCode($user);

        $this->actingAs($user)
            ->post(route('privacy.export.send'))
            ->assertSessionHasErrors('resend');

        Event::assertDispatchedTimes(OtpRequested::class, 1);
    }

    public function test_guests_cannot_reach_the_export(): void
    {
        $this->get(route('privacy.export'))->assertRedirect(route('login'));
        $this->post(route('privacy.export.verify'), ['otp' => '123456'])->assertRedirect(route('login'));
        $this->get(route('privacy.export.file'))->assertRedirect(route('login'));
    }

    public function test_the_file_cannot_be_fetched_without_a_fresh_verification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('privacy.export.file'))->assertRedirect(route('privacy.export'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.data_exported']);
    }

    public function test_downloads_are_rate_limited_per_day(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear('data-export:'.$user->id);

        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit('data-export:'.$user->id, 86400);
        }

        // Refused before any code is emailed, straight back to settings.
        $this->actingAs($user)
            ->get(route('privacy.export'))
            ->assertRedirect(route('dashboard.profile'))
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->post(route('privacy.export.send'))
            ->assertRedirect(route('dashboard.profile'))
            ->assertSessionHas('error');

        Event::assertNotDispatched(OtpRequested::class);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.data_exported']);
    }
}
