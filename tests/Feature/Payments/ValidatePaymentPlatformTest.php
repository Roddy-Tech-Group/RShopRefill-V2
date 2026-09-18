<?php

namespace Tests\Feature\Payments;

use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\RecoveryAttempt;
use App\Models\SiteSetting;
use App\Models\TransactionRecovery;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The payments:validate command is the pre-deployment regression suite for
 * the Payment Intelligence platform. These tests guarantee the suite itself
 * stays green and side-effect free.
 */
class ValidatePaymentPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_platform_validation_passes(): void
    {
        $this->artisan('payments:validate')->assertExitCode(0);
    }

    public function test_validation_rolls_back_every_probe_record(): void
    {
        $this->artisan('payments:validate')->assertExitCode(0);

        $this->assertSame(0, PaymentOutcome::count());
        $this->assertSame(0, PaymentSession::count());
        $this->assertSame(0, TransactionRecovery::count());
        $this->assertSame(0, RecoveryAttempt::count());
        $this->assertSame(0, User::where('email', 'like', 'payval%')->count());
    }

    /**
     * The probes flip feature flags inside a transaction that is rolled back.
     * The rollback restores each row, but not the cache: a probe that reads a
     * flag after flipping it caches the temporary value for an hour. Here
     * payment_insights is flipped back on and then read again while outcomes
     * are recorded, so a production run with insights switched off used to
     * leave them reading on until the cache expired. The same trap would catch
     * payment_backoff_enforce the moment a probe read it late.
     */
    public function test_flags_read_their_real_value_after_the_suite_runs(): void
    {
        SiteSetting::put('features.payment_insights_enabled', 'off', 'features');
        SiteSetting::put('features.payment_backoff_enforce_enabled', 'on', 'features');

        Artisan::call('payments:validate');

        $this->assertFalse(FeatureFlag::on('payment_insights'), 'a probe left payment_insights reading on from a stale cache');
        $this->assertTrue(app(PaymentBackoffManager::class)->enforcementEnabled(), 'a probe left enforcement reading off from a stale cache');
    }

    public function test_static_mode_passes_without_touching_the_database(): void
    {
        $this->artisan('payments:validate', ['--static' => true])->assertExitCode(0);

        $this->assertSame(0, PaymentOutcome::count());
        $this->assertSame(0, User::count());
    }

    public function test_payment_insights_dashboard_renders_with_all_metric_sections(): void
    {
        Volt::test('admin.payment-insights.dashboard')
            ->assertSee('Checkouts Started')
            // The two rates must both be present: one blended number hides
            // whichever of the two problems the platform actually has.
            ->assertSee('Payment Success')
            ->assertSee('Checkout Conversion')
            ->assertSee('Where Payments Die')
            ->assertSee('Daily Trend')
            ->assertSee('By Payment Method')
            ->assertSee('Recent Lost Payments')
            // With no browser-side failures the panel collapses to one line,
            // so only its title is guaranteed here.
            ->assertSee('Frontend Failures')
            ->assertSee('Transaction Recovery')
            ->assertSee('Provider Success Rate')
            ->assertSee('Recovery Skips')
            ->assertSee('Customer Feedback');
    }
}
