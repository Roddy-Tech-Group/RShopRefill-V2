<?php

namespace App\Domain\Admin\Services;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Payment\Services\PaymentEligibilityService;
use App\Models\SiteSetting;

/**
 * Writes one admin-editable setting, refusing the flips that would take
 * payments down, and audits every change with who, which key, and before and
 * after.
 *
 * Shared by System Settings and Gateway settings, so a switch keeps its safety
 * interlocks whichever page flips it.
 */
final class FeatureSettingWriter
{
    private const TRUTHY = ['on', 'true', '1'];

    public function __construct(
        private PaymentEligibilityService $eligibility,
        private AuditLogService $audit,
    ) {}

    /**
     * Whether a setting is managed on the Gateway settings page rather than in
     * System Settings: the gateway kill switches, eligibility enforcement and
     * the gateway-by-category eligibility cells.
     */
    public static function belongsToGatewaySettings(string $key): bool
    {
        return (str_starts_with($key, 'features.gateway_') && str_ends_with($key, '_enabled'))
            || str_starts_with($key, 'features.eligibility_')
            || $key === 'features.payment_eligibility_enforce_enabled';
    }

    /**
     * Save the value, or return why it was refused.
     *
     * The value is stored as given; a string that is valid JSON is decoded
     * first, the way System Settings always stored what the admin typed.
     */
    public function write(string $key, string $value): ?string
    {
        $existing = SiteSetting::query()->where('key', $key)->first();
        $previous = $existing?->value;

        $decoded = json_decode($value, true);
        $stored = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;

        if ($refusal = $this->refusal($key, $stored)) {
            return $refusal;
        }

        // The existing group and description go back through put(): its
        // "general" default would otherwise re-group the row on every save and
        // the toggle would vanish from its proper card.
        SiteSetting::put(
            $key,
            $stored,
            $existing?->group ?? 'general',
            $existing?->description,
        );

        $this->audit->log(
            action: 'setting.updated',
            model: $existing,
            beforeState: ['value' => $previous],
            afterState: ['value' => $stored],
            metadata: ['key' => $key, 'group' => $existing?->group ?? 'general'],
        );

        return null;
    }

    private function refusal(string $key, mixed $stored): ?string
    {
        // Only the two interlocked keys are switches; a setting holding a list
        // or an object is not read as one (casting it would fail the save).
        if ($key !== 'features.payment_eligibility_enforce_enabled' && ! str_starts_with($key, 'features.eligibility_')) {
            return null;
        }

        $isOn = is_scalar($stored) && in_array(strtolower((string) $stored), self::TRUTHY, true);

        // Enforcing the eligibility matrix with ZERO approved cells would refuse
        // every restricted-category cart on every gateway: a one-toggle
        // catalogue outage. PaymentEligibilityService carries the same guard as
        // an un-bypassable runtime backstop.
        if ($key === 'features.payment_eligibility_enforce_enabled'
            && $isOn
            && $this->eligibility->approvedCellCount() === 0) {
            return 'Refused: no eligibility cell is approved yet. Approve at least one gateway x category cell before enforcing, or every cart would be refused.';
        }

        // The same interlock from the other side: switching off the LAST
        // approved cell while enforcing leaves a lockdown with nothing approved.
        if (str_starts_with($key, 'features.eligibility_')
            && ! $isOn
            && $this->eligibility->isLastApprovedCell($key)) {
            return 'Refused: this is the last approved cell and enforcement is on. Approve another cell first, or switch enforcement off, otherwise every restricted cart would be refused.';
        }

        return null;
    }
}
