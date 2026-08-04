<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\RecoveryAttempt;
use App\Models\TransactionRecovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Frontend Failures')
            ->assertSee('Environment Signals')
            ->assertSee('Transaction Recovery')
            ->assertSee('Provider Success Rate')
            ->assertSee('Recovery Skips')
            ->assertSee('Customer Feedback');
    }
}
