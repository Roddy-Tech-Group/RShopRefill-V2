<?php

namespace App\Livewire\Dashboard\Settings;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Services\PreferenceManager;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Per-topic email and push toggles.
 *
 * Keyed by PREFERENCE GROUP, not by category, because that is what the storage
 * is. communication_preferences holds one {group}_email / {group}_push column
 * pair per topic, and several categories share a group - an order confirmation
 * and a delivery notice are both "orders". An earlier version of this component
 * offered a switch per category and wrote a `channels` array to a `category`
 * row, and the table has neither column, so every toggle raised an unknown
 * column error. It was reachable from nowhere, which is the only reason that
 * never surfaced.
 *
 * Reads and writes go through PreferenceManager, the same service the dispatch
 * path consults, so what this page shows and what actually gets sent cannot
 * drift apart.
 *
 * Note the account settings page already carries these switches. This component
 * is not mounted on any route.
 */
class NotificationPreferences extends Component
{
    /** @var array<string, array{email: bool, push: bool}> */
    public array $preferences = [];

    public function mount(): void
    {
        $this->loadPreferences();
    }

    /**
     * The topics a customer may switch off, in display order, with the label
     * each one carries. Mandatory topics (security, verification, compliance)
     * have no group and never appear.
     *
     * @return array<string, string>
     */
    public function groups(): array
    {
        $labels = [
            'orders' => 'Orders and delivery',
            'wallet' => 'Wallet activity',
            'transactions' => 'Payments and receipts',
            'product_updates' => 'Product updates',
            'marketing' => 'Offers and promotions',
        ];

        // Intersect with the groups the enum actually maps to, so a retired
        // topic disappears from the page instead of writing a dead column.
        $live = collect(CommunicationCategory::cases())
            ->filter(fn (CommunicationCategory $category) => $category->isUserDisableable())
            ->map(fn (CommunicationCategory $category) => $category->preferenceGroup())
            ->filter()
            ->unique()
            ->all();

        return array_intersect_key($labels, array_flip($live));
    }

    public function loadPreferences(): void
    {
        $prefs = $this->preferenceManager()->getPreferences(Auth::user());

        $this->preferences = [];

        foreach (array_keys($this->groups()) as $group) {
            $this->preferences[$group] = [
                'email' => (bool) ($prefs->{$group.'_email'} ?? true),
                'push' => (bool) ($prefs->{$group.'_push'} ?? true),
            ];
        }
    }

    public function togglePreference(string $group, string $channel): void
    {
        if (! array_key_exists($group, $this->groups()) || ! in_array($channel, ['email', 'push'], true)) {
            return;
        }

        $value = ! $this->preferences[$group][$channel];
        $this->preferences[$group][$channel] = $value;

        $this->preferenceManager()->updatePreferences(Auth::user(), [
            $group.'_'.$channel => $value,
        ]);

        $this->dispatch('notify', ['type' => 'success', 'message' => 'Preferences updated.']);
    }

    /**
     * Resolved per call rather than held on the component. Livewire rebuilds
     * the instance on every request and only public properties survive, so a
     * stored service would be null on the second one.
     */
    private function preferenceManager(): PreferenceManager
    {
        return app(PreferenceManager::class);
    }

    public function render()
    {
        return view('livewire.dashboard.settings.notification-preferences');
    }
}
