<?php

namespace App\Domain\Payment\Support;

use App\Models\SiteSetting;

/**
 * How a customer approves a mobile money payment by hand when the operator's
 * prompt never reaches the handset.
 *
 * The rails that prompt the handset (the Francophone rail, M-Pesa) send the
 * customer a push they approve with their PIN, and the gateway's answer to
 * the charge is only "Transaction in progress". Prompts do go astray, and
 * each operator has a dial code that opens the pending payment instead. The
 * code and the steps are operator facts that change without notice, so they
 * live in site settings, one pair per country and network, where an
 * operator can correct them. No setting means no hint: the modal shows only
 * the prompt instruction.
 *
 * Keys: payments.momo_approval.{ISO country}.{NETWORK}.code and .steps,
 * where steps is one line per step. MobileMoneyApprovalSeeder writes the
 * starting values.
 */
final class MobileMoneyApproval
{
    public const SETTING_PREFIX = 'payments.momo_approval';

    /**
     * @return array{network: string, code: string, steps: list<string>}|null
     */
    public static function for(?string $country, ?string $networkLabel): ?array
    {
        if (blank($country) || blank($networkLabel)) {
            return null;
        }

        $prefix = self::key($country, $networkLabel);
        $code = trim((string) SiteSetting::get($prefix.'.code', ''));

        if ($code === '') {
            return null;
        }

        $steps = preg_split('/\R+/', trim((string) SiteSetting::get($prefix.'.steps', ''))) ?: [];
        $steps = array_values(array_filter(array_map('trim', $steps), fn (string $step): bool => $step !== ''));

        return [
            'network' => $networkLabel,
            'code' => $code,
            'steps' => $steps,
        ];
    }

    /** The setting key prefix for a country and network, without .code or .steps. */
    public static function key(string $country, string $networkLabel): string
    {
        $network = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $networkLabel));

        return self::SETTING_PREFIX.'.'.strtoupper(trim($country)).'.'.$network;
    }
}
