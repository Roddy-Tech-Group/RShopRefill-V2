<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Models\CommunicationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Manages user notification preferences with per-category × per-channel
 * granularity.
 *
 * Opt-out model: every channel/category defaults ON. Users disable
 * individual combinations from their notification settings page.
 *
 * Security, Verify, and Compliance categories are enforced as always-on
 * at the CommunicationManager level — this service is not even consulted
 * for those categories.
 */
class PreferenceManager
{
    /**
     * Get or create preferences for a recipient.
     */
    public function getPreferences(Model $recipient): CommunicationPreference
    {
        // Only User models have preferences; Admin models default to all-on.
        if (! ($recipient instanceof User)) {
            return new CommunicationPreference($this->defaults());
        }

        return CommunicationPreference::firstOrCreate(
            ['user_id' => $recipient->id],
            $this->defaults()
        );
    }

    /**
     * Update preferences for a user.
     */
    public function updatePreferences(Model $recipient, array $settings): CommunicationPreference
    {
        $prefs = $this->getPreferences($recipient);

        // Filter out any attempt to disable security fields.
        $safe = array_diff_key($settings, array_flip([
            'security_notifications', // Legacy column guard
        ]));

        $prefs->update($safe);

        return $prefs;
    }

    /**
     * Check if a specific category × channel combination is allowed.
     */
    public function isAllowed(
        Model $recipient,
        CommunicationCategory $category,
        CommunicationChannel $channel,
    ): bool {
        // Mandatory categories bypass preferences entirely.
        if (! $category->isUserDisableable()) {
            return true;
        }

        $prefs = $this->getPreferences($recipient);

        // 1. Global channel kill switch
        if ($channel === CommunicationChannel::Email && ! $prefs->email_enabled) {
            return false;
        }
        if ($channel === CommunicationChannel::Push && ! $prefs->push_enabled) {
            return false;
        }

        // 2. Per-category × channel preference
        $group = $category->preferenceGroup();
        if ($group === null) {
            return true; // No preference group = always allowed
        }

        $columnSuffix = match ($channel) {
            CommunicationChannel::Email => '_email',
            CommunicationChannel::Push => '_push',
            default => null,
        };

        if ($columnSuffix === null) {
            return true; // Channel has no preference column yet (SMS, WhatsApp)
        }

        $column = $group.$columnSuffix;

        // Guard against missing columns during migration
        if (! array_key_exists($column, $prefs->getAttributes())) {
            return true;
        }

        return (bool) $prefs->{$column};
    }

    /**
     * Default preference values — everything on.
     */
    private function defaults(): array
    {
        return [
            'email_enabled' => true,
            'push_enabled' => true,
            'wallet_email' => true,
            'wallet_push' => true,
            'orders_email' => true,
            'orders_push' => true,
            'transactions_email' => true,
            'transactions_push' => true,
            'product_updates_email' => true,
            'product_updates_push' => true,
            'marketing_email' => true,
            'marketing_push' => true,
        ];
    }
}
