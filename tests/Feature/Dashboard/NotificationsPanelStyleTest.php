<?php

namespace Tests\Feature\Dashboard;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The dashboard bell's panel (2026-09-23): a card list on desktop, a bottom
 * sheet on phones.
 *
 *   - Each notification is a row: a tinted icon tile (priority sets the tint,
 *     the category the glyph), a 15px title, a 14px message, the action the
 *     sender attached (metadata.url) as a blue pill, and a "Read" pill that
 *     marks the row read. Read rows lose the pill and dim.
 *   - On phones the same panel is a bottom sheet: full width, a handle at
 *     its top, sliding up over a dimmed page. The enter/leave states are
 *     classes (dash-popup-hidden / -shown) that app.css remaps below lg.
 */
class NotificationsPanelStyleTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'type' => 'order_fulfilled',
            'title' => 'Your gift card is ready',
            'message' => 'The code is waiting on your order page.',
            'channel' => 'in_app',
            'status' => 'sent',
            'priority' => 'normal',
            'sent_at' => now(),
        ], $overrides));
    }

    private function panel(User $user): string
    {
        $this->withoutVite();
        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $panel = substr($html, strpos($html, 'class="dashboard-frame-popup dashboard-notification-panel'));

        return substr($panel, 0, strpos($panel, '</header>'));
    }

    public function test_each_notification_is_a_card_row_with_its_action_and_a_read_pill(): void
    {
        $user = User::factory()->create();
        $unread = $this->notify($user, ['metadata' => ['url' => '/dashboard/orders', 'cta' => 'View order']]);
        $read = $this->notify($user, ['type' => 'wallet_funding', 'title' => 'Wallet funded', 'priority' => 'high', 'read_at' => now()->subHour(), 'created_at' => now()->subDay()]);

        $panel = $this->panel($user);

        // Heading, then one row per notification.
        $this->assertStringContainsString('<h3 class="text-[18px] font-semibold text-zinc-900 dark:text-white">Notifications</h3>', $panel);
        $this->assertSame(2, substr_count($panel, 'class="dashboard-notification-row '));

        // The unread row: blue tile (normal priority), the bag glyph for an
        // order, the sender's action as a pill, and the Read pill.
        $row = substr($panel, strpos($panel, 'Your gift card is ready') - 900, 4000);
        $this->assertStringContainsString('rounded-[10px] bg-blue-600/10 text-blue-600', $row);
        $this->assertStringContainsString('d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5', $row);
        $this->assertStringContainsString('<p class="text-[15px] font-semibold leading-snug text-zinc-900 dark:text-white">Your gift card is ready</p>', $row);
        $this->assertStringContainsString('<p class="mt-1 text-[14px] leading-snug text-zinc-600 dark:text-zinc-400">The code is waiting on your order page.</p>', $row);
        $this->assertStringContainsString('wire:click="markRead(\''.$unread->id.'\')"', $row);
        $this->assertStringContainsString('aria-label="Mark as read"', $row);
        $this->assertMatchesRegularExpression('/href="\/dashboard\/orders"\s+wire:navigate\.hover/', $row);
        $this->assertStringContainsString('>View order</a>', $row);

        // The read row: amber tile (high), the wallet glyph, dimmed, no pills.
        // The row's opening tag sits well over 900 characters before its
        // title once the tile's glyph path and the indentation are counted.
        $readRow = substr($panel, strpos($panel, 'Wallet funded') - 1600, 2400);
        $this->assertStringContainsString('dashboard-notification-row-read', $readRow);
        $this->assertStringContainsString('bg-amber-500/10 text-amber-600', $readRow);
        $this->assertStringContainsString('d="M21 12a2.25 2.25 0 00-2.25-2.25H15', $readRow);
        $this->assertStringNotContainsString('markRead(\''.$read->id.'\')', $panel);
        $this->assertSame(1, substr_count($panel, 'aria-label="Mark as read"'));

        // Mark all read stays at the foot while something is unread.
        $this->assertStringContainsString('wire:click="markAllRead"', $panel);
    }

    public function test_an_external_action_opens_in_a_new_tab_and_a_missing_one_draws_no_pill(): void
    {
        $user = User::factory()->create();
        $this->notify($user, ['type' => 'campaign', 'title' => 'Weekend offer', 'metadata' => ['url' => 'https://example.com/offer']]);
        $this->notify($user, ['type' => 'security_incident', 'title' => 'New device', 'priority' => 'critical']);

        $panel = $this->panel($user);

        $this->assertMatchesRegularExpression('/href="https:\/\/example\.com\/offer"\s+target="_blank" rel="noopener"/', $panel);
        $this->assertStringContainsString('>View</a>', $panel);
        $this->assertSame(1, substr_count($panel, 'rounded-full bg-blue-600 px-4 py-2 text-[13px]'), 'only the row with a url gets an action pill');
        // The critical row: red tile, the shield glyph.
        $this->assertStringContainsString('bg-red-500/10 text-red-600', $panel);
        $this->assertStringContainsString('d="M9 12.75L11.25 15 15 9.75', $panel);
        // Megaphone for the campaign.
        $this->assertStringContainsString('d="M10.34 15.84c', $panel);
    }

    public function test_the_read_pill_marks_that_row_read(): void
    {
        $user = User::factory()->create();
        $n = $this->notify($user);

        Volt::actingAs($user)->test('notifications-menu')->call('markRead', $n->id);

        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_the_panel_is_a_bottom_sheet_on_phones(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $panel = $this->panel($user);
        $html = $this->actingAs($user)->get('/dashboard')->getContent();

        // The handle, the dimming backdrop, and the class-based motion states.
        $this->assertStringContainsString('<div class="flex shrink-0 justify-center pt-2.5 lg:hidden" aria-hidden="true">', $panel);
        $this->assertStringContainsString('class="dashboard-notification-backdrop fixed inset-0 z-40 bg-zinc-900/45 lg:hidden"', $html);
        // (The transition attributes sit before the class attribute the panel
        // slice starts at, so they are checked on the page.)
        $this->assertStringContainsString('x-transition:enter-start="dash-popup-hidden"', $html);
        $this->assertStringContainsString('x-transition:leave-end="dash-popup-hidden"', $html);
        $this->assertStringContainsString('dash-popup-motion', $html);
        // Still the shared frosted surface the other pull-downs wear.
        $this->assertStringContainsString('rounded-[12px] bg-white/80 backdrop-blur-xl shadow-xl shadow-zinc-900/15', $panel);

        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));
        $this->assertStringContainsString('.dash-popup-hidden { opacity: 0; transform: translateY(-4px); }', $css);
        preg_match('/body\.dashboard \.dashboard-notification-panel \{\s*top: auto;\s*bottom: 0;\s*left: 0;\s*right: 0;([^}]*)\}/s', $css, $sheet);
        $this->assertNotEmpty($sheet, 'the phone sheet rule is missing');
        $this->assertStringContainsString('max-height: 85dvh', $sheet[1]);
        $this->assertStringContainsString('border-radius: 24px 24px 0 0', $sheet[1]);
        // Solid white on phones: frosted over the dimmed page it read as grey.
        $this->assertStringContainsString('background-color: #ffffff', $sheet[1]);
        $this->assertStringContainsString('backdrop-filter: none', $sheet[1]);
        $this->assertStringContainsString('body.dashboard .dashboard-notification-panel.dash-popup-hidden { opacity: 1; transform: translateY(100%); }', $css);
        $this->assertMatchesRegularExpression('/body\.dashboard \.dashboard-notification-panel\.dash-popup-motion \{\s*transition-duration: 300ms;/s', $css);
    }
}
