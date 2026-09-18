<?php

namespace Tests\Feature\Settings;

use Tests\TestCase;

/**
 * Settings cards carry no border. The Danger zone is the one exception: its
 * red edge marks the section that signs you out or closes the account.
 */
class SettingsCardBordersTest extends TestCase
{
    /** Named settingsSource, not view(): TestCase already defines view(). */
    private function settingsSource(string $name): string
    {
        return file_get_contents(resource_path('views/livewire/settings/'.$name.'.blade.php'));
    }

    public function test_settings_cards_have_no_border(): void
    {
        foreach (['profile', 'appearance', 'transaction-pin', 'security'] as $name) {
            $source = $this->settingsSource($name);

            $this->assertStringNotContainsString('dash-shimmer border border-zinc-200', $source, $name.' still borders a card.');
            $this->assertStringNotContainsString('hover:border-green-200', $source, $name.' still has the green hover edge.');
            $this->assertStringNotContainsString('hover:border-blue-200', $source, $name.' still has the blue hover edge.');
        }
    }

    /**
     * The profile card cannot use overflow-hidden (it would clip the country
     * dropdown), so its last detail row takes the card's bottom radius itself,
     * or its square hover highlight paints past the rounded corners.
     */
    public function test_the_profile_cards_last_row_hover_stays_inside_the_corners(): void
    {
        $this->assertStringContainsString(
            '<div class="divide-inset" :class="! editingProfile && \'[&>*:last-child]:rounded-b-[12px]\'">',
            $this->settingsSource('profile')
        );
    }

    public function test_the_danger_zone_card_is_edged_in_red(): void
    {
        $profile = $this->settingsSource('profile');

        $heading = strpos($profile, '>Danger zone</h2>');
        $this->assertNotFalse($heading);

        $card = substr($profile, $heading, 600);
        $this->assertStringContainsString('border border-red-300', $card);
        $this->assertStringContainsString('dark:border-red-500/50', $card);
    }
}
