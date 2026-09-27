<?php

namespace Database\Seeders;

use App\Domain\Payment\Support\MobileMoneyApproval;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

/**
 * Starting values for the manual approval hints the mobile money modal
 * shows when no prompt reaches the handset (see MobileMoneyApproval).
 *
 * These are the operators' published service codes. The MTN Cameroon menu
 * is reached with *126# (MTN's own help pages); Orange Money Cameroon's
 * menu is #150#, and #150*50# is the manual validation for a pending web
 * payment that the merchant guides document for the case where the push
 * does not arrive. Operators move menus without notice, so a key that is
 * already set is left alone: an operator's correction in settings outlives
 * a reseed.
 */
class MobileMoneyApprovalSeeder extends Seeder
{
    /**
     * @var array<string, array<string, array{code: string, steps: list<string>}>>
     */
    private const DEFAULTS = [
        'CM' => [
            'MTN' => [
                'code' => '*126#',
                'steps' => [
                    'Dial *126# on the phone linked to your MTN Mobile Money account',
                    'Open the option for pending or awaiting approvals',
                    'Approve this payment with your MoMo PIN',
                ],
            ],
            'Orange' => [
                'code' => '#150*50#',
                'steps' => [
                    'Dial #150*50# on the phone linked to your Orange Money account',
                    'Confirm the pending payment with your Orange Money PIN',
                ],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $country => $networks) {
            foreach ($networks as $network => $hint) {
                $prefix = MobileMoneyApproval::key($country, $network);

                if (SiteSetting::query()->where('key', $prefix.'.code')->exists()) {
                    continue;
                }

                SiteSetting::put(
                    $prefix.'.code',
                    $hint['code'],
                    'payments',
                    "Dial code a {$network} customer in {$country} uses to approve a pending mobile money payment when no prompt arrives. Blank hides the hint.",
                );
                SiteSetting::put(
                    $prefix.'.steps',
                    implode("\n", $hint['steps']),
                    'payments',
                    "Steps shown under the {$network} ({$country}) dial code, one per line.",
                );
            }
        }
    }
}
