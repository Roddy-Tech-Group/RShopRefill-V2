<?php

namespace Tests\Feature\Compliance;

use App\Console\Commands\RetentionRopaCommand;
use Tests\TestCase;

/**
 * The records of processing are rendered from the same config the retention
 * commands run on, so a window can never be published that is not enforced.
 */
class RecordsOfProcessingTest extends TestCase
{
    public function test_the_register_quotes_the_live_retention_windows(): void
    {
        config([
            'retention.account_closure_grace_days' => 30,
            'retention.closed_account_identity_years' => 5,
            'retention.closed_account_financial_years' => 7,
            'retention.communication_logs_days' => 730,
        ]);

        $markdown = app(RetentionRopaCommand::class)->render();

        foreach ([
            '# Records of Processing Activities',
            '| Account closure grace period | 30 days |',
            'kept in the closed-account register for 5 years',
            'Up to 7 years after the account is closed',
            'delivery logs 730 days',
            'Nigeria Data Protection Commission',
        ] as $expected) {
            $this->assertStringContainsString($expected, $markdown);
        }

        $this->artisan('retention:ropa')->assertExitCode(0);
    }

    public function test_every_activity_names_a_lawful_basis_and_a_retention(): void
    {
        foreach (config('retention.processing_activities') as $activity) {
            $this->assertNotEmpty($activity['lawful_basis'], $activity['activity'].' has no lawful basis');
            $this->assertNotEmpty($activity['retention'], $activity['activity'].' has no retention');
        }
    }
}
