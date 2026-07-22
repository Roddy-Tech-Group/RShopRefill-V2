<?php

namespace App\Livewire\Dashboard\Settings;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Models\CommunicationPreference;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NotificationPreferences extends Component
{
    public array $preferences = [];

    public function mount()
    {
        $this->loadPreferences();
    }

    public function loadPreferences()
    {
        $user = Auth::user();
        $dbPreferences = CommunicationPreference::where('user_id', $user->id)->get();

        $this->preferences = [];

        foreach (CommunicationCategory::cases() as $category) {
            if (! $category->isUserDisableable()) {
                continue; // Skip mandatory categories
            }

            $pref = $dbPreferences->where('category', $category->value)->first();

            $this->preferences[$category->value] = [
                'email' => $pref ? in_array(CommunicationChannel::Email->value, $pref->channels ?? []) : true,
                'push' => $pref ? in_array(CommunicationChannel::Push->value, $pref->channels ?? []) : true,
                'in_app' => true, // Always true
            ];
        }
    }

    public function togglePreference(string $categoryValue, string $channelValue)
    {
        $user = Auth::user();
        $category = CommunicationCategory::tryFrom($categoryValue);

        if (! $category || ! $category->isUserDisableable()) {
            return;
        }

        $this->preferences[$categoryValue][$channelValue] = ! $this->preferences[$categoryValue][$channelValue];

        $activeChannels = [];
        if ($this->preferences[$categoryValue]['email']) {
            $activeChannels[] = CommunicationChannel::Email->value;
        }
        if ($this->preferences[$categoryValue]['push']) {
            $activeChannels[] = CommunicationChannel::Push->value;
        }
        $activeChannels[] = CommunicationChannel::InApp->value;

        CommunicationPreference::updateOrCreate(
            ['user_id' => $user->id, 'category' => $categoryValue],
            ['channels' => $activeChannels]
        );

        $this->dispatch('notify', ['type' => 'success', 'message' => 'Preferences updated successfully.']);
    }

    public function render()
    {
        return view('livewire.dashboard.settings.notification-preferences');
    }
}
