<?php

namespace Tests\Feature\Communication;

use App\Models\CommunicationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidateCommunicationPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_validation_suite_passes_with_no_failures(): void
    {
        $this->artisan('communication:validate')
            ->expectsOutputToContain('Overall Platform Health')
            ->assertExitCode(0);
    }

    public function test_validation_suite_rolls_back_all_probe_data(): void
    {
        $this->artisan('communication:validate')->assertExitCode(0);

        $this->assertSame(0, CommunicationLog::count());
        $this->assertSame(0, User::where('email', 'like', 'comms-validation%')->count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_static_mode_runs_wiring_checks_without_probe_data(): void
    {
        $this->artisan('communication:validate --static')
            ->expectsOutputToContain('Overall Platform Health')
            ->assertExitCode(0);

        $this->assertSame(0, CommunicationLog::count());
        $this->assertSame(0, User::count());
    }
}
