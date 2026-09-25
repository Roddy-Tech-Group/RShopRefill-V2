<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * For Partners (/dashboard/rewards): three options along the top, Refer & Earn,
 * Rcoin Earnings (the old Rcoin page, unchanged, and the default) and the
 * Partnership program with a New badge.
 */
class ForPartnersTabsTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $query = ''): string
    {
        return $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.rewards').$query)
            ->assertOk()
            ->getContent();
    }

    /** The opening tag of the element carrying $marker, up to its closing '>'. */
    private function openingTag(string $html, string $marker): string
    {
        $at = strpos($html, $marker);
        $this->assertNotFalse($at, $marker.' is missing.');

        return substr($html, $at, strpos($html, '>', $at) - $at);
    }

    public function test_the_three_tabs_render_in_order_with_the_new_badge(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('role="tablist" aria-label="For Partners"', $html);

        $last = -1;
        foreach (['id="partners-tab-refer"', 'id="partners-tab-rcoin"', 'id="partners-tab-partners"'] as $tab) {
            $at = strpos($html, $tab);
            $this->assertNotFalse($at, $tab.' is missing.');
            $this->assertGreaterThan($last, $at, $tab.' is out of order.');
            $last = $at;
        }

        $partnerTab = substr($html, strpos($html, 'id="partners-tab-partners"'), 1200);
        $this->assertStringContainsString('Partnership program', $partnerTab);
        $this->assertStringContainsString('>New</span>', $partnerTab);
    }

    public function test_rcoin_earnings_holds_the_rcoin_page_and_refer_opens_by_default(): void
    {
        $html = $this->page();

        // Refer & Earn opens the page; Rcoin Earnings is a click away.
        $this->assertStringContainsString("tab: 'refer'", $html);

        $panel = strpos($html, 'id="partners-panel-rcoin"');
        $next = strpos($html, 'id="partners-panel-partners"');
        $this->assertNotFalse($panel);

        $rcoin = substr($html, $panel, $next - $panel);
        foreach (['Your Rcoin', 'Spend your Rcoin', 'Rcoin Reward Tiers', 'Rcoin history'] as $section) {
            $this->assertStringContainsString($section, $rcoin, $section.' should sit under Rcoin Earnings.');
        }

        // Earnings tracking only: no referral link or referral programme here.
        foreach (['Earn more Rcoin', 'Refer friends, earn Rcoin', "copied ? 'Copied' : 'Copy'", 'On sign-up', 'referals.webp', 'referral'] as $referral) {
            $this->assertStringNotContainsString($referral, $rcoin, $referral.' should not be under Rcoin Earnings.');
        }

        // Only the default panel is visible before Alpine starts.
        $this->assertStringNotContainsString('display: none', $this->openingTag($html, 'id="partners-panel-refer"'));
        $this->assertStringContainsString('display: none', $this->openingTag($html, 'id="partners-panel-rcoin"'));
    }

    public function test_the_tab_query_opens_that_tab(): void
    {
        $html = $this->page('?tab=partners');

        $this->assertStringContainsString('display: none', $this->openingTag($html, 'id="partners-panel-rcoin"'));
        $this->assertStringNotContainsString('display: none', $this->openingTag($html, 'id="partners-panel-partners"'));
        $this->assertStringContainsString('href="'.route('shop.affiliates').'"', $html);
    }

    /**
     * Refer & Earn follows the reference layout (illustration, pill, big heading,
     * link row with share + Copy, how it pays, two stats) with live figures only.
     */
    public function test_refer_and_earn_follows_the_reference_layout_with_live_figures(): void
    {
        config()->set('dashboard.earn_up_to_usd', 100);
        Setting::set('referral_reward_percentage', 0.5);
        Setting::set('referral_enabled', true);

        $html = $this->page('?tab=refer');

        $start = strpos($html, 'id="partners-panel-refer"');
        $panel = substr($html, $start, strpos($html, 'id="partners-panel-rcoin"') - $start);

        $last = -1;
        foreach (['bg-[#0c1337]', "Earn Rcoin every time a friend's order completes", 'assets/PWAicon.webp', 'Paid in Rcoin', 'Invite others and earn up to $100', 'Gift Cards, eSIMs, Topups, Bills', 'aria-label="Change your referral code"', "copied ? 'Copied' : 'Copy'", 'They earn <span class="text-white">0.5%</span> per order, you earn <span class="text-white">0.5%</span> too', 'That is <span class="text-white">1 Rcoin</span> each per $1 they spend', 'Added to your Rcoin balance when their order completes.', 'people referred', 'Rcoin</span> minted so far'] as $piece) {
            $at = strpos($panel, $piece);
            $this->assertNotFalse($at, $piece.' is missing from Refer & Earn.');
            $this->assertGreaterThan($last, $at, $piece.' is out of order.');
            $last = $at;
        }

        // No repeated wording: "when their order completes" is said once.
        $this->assertSame(1, substr_count($panel, 'when their order completes'));

        // The info icon carries the multiplier tiers, from config, and links to
        // the inquiry form. A standard account is not told it is on a multiplier.
        $this->assertStringContainsString('Every account starts at 1x.', $panel);
        $this->assertStringContainsString('influencer, which pays 2x on each reward, or an ambassador, which pays 3x.', $panel);
        $this->assertStringContainsString('href="'.route('shop.affiliates').'"', $panel);
        $this->assertStringNotContainsString('You are on', $panel);

        // The design's placeholder offers and figures are not claimed.
        foreach (['Refer 3 people', 'Limited time', 'They save', 'of 3 joined', 'commission paid', 'Hosting'] as $invented) {
            $this->assertStringNotContainsString($invented, $panel);
        }

        // Underlined tabs, styled from aria-selected only, so switching moves the
        // underline instead of leaving it on the tab the page opened on.
        // Always-dark card, and no CSS gradients (the dome is solid shapes + blur).
        $this->assertStringContainsString('relative overflow-hidden rounded-[22px] bg-[#17171a]', $panel);
        // One face, one weight: DM Sans 399 across the card, emphasis by colour.
        $this->assertStringContainsString(".partner-hero, .partner-hero * { font-family: 'DM Sans', sans-serif; font-weight: 399; }", $panel);
        // Checked on the hero's own markup: the shared components it renders
        // may carry weight utilities, and the rule above overrides them all.
        $source = file_get_contents(resource_path('views/dashboard/rewards.blade.php'));
        $heroStart = strpos($source, 'partner-hero theme-static');
        $heroEnd = strpos($source, '{{-- Refer a product', $heroStart);
        $this->assertNotFalse($heroEnd, 'the Refer a product section no longer follows the hero');
        $hero = substr($source, $heroStart, $heroEnd - $heroStart);
        foreach (['font-bold', 'font-extrabold', 'font-semibold', 'font-medium'] as $weight) {
            $this->assertStringNotContainsString($weight, $hero, $weight.' should not appear inside the hero card.');
        }
        // Single dark layer: no outer #08080a frame around the card.
        $this->assertStringNotContainsString('bg-[#08080a]', $panel);
        $this->assertStringNotContainsString('gradient', $panel);

        $this->assertStringContainsString('border-b-2 border-transparent pb-3 text-sm font-semibold', $html);
        $this->assertStringContainsString('aria-selected:border-[#0c1337]', $html);
        $this->assertStringNotContainsString(':class="tab ===', $html);
        $this->assertStringContainsString('id="partners-tab-refer"', $html);
        $this->assertMatchesRegularExpression('/id="partners-tab-refer"[^>]*aria-selected="true"/', $html);
        $this->assertMatchesRegularExpression('/id="partners-tab-rcoin"[^>]*aria-selected="false"/', $html);
    }

    /**
     * The strip runs on real data: the platform's referral count and the Rcoin it
     * has minted, with the biggest referrer's own avatar on it. The customer's
     * own count still drives the "friend joined" line above the card.
     */
    public function test_referral_stats_are_the_platforms_real_numbers(): void
    {
        Setting::set('rcoin_usd_rate', 0.005);
        $referrer = User::factory()->create(['referral_code' => 'realref']);
        $friend = User::factory()->create(['name' => 'Grace Friend']);
        Referral::create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $friend->id,
            'status' => 'active',
            'total_rewards_generated' => 250,
            'total_orders_completed' => 1,
        ]);
        // Minted Rcoin is every RCOIN credit on record, not the referral column.
        app(WalletService::class)->credit(
            wallet: app(WalletService::class)
                ->getOrCreateWallet($referrer, Currency::RCOIN),
            amount: 250,
            category: TransactionCategory::RewardReferral,
            description: 'Referral bonus',
            idempotencyKey: 'test-minted-250',
        );

        $html = $this->withoutVite()->actingAs($referrer)->get(route('dashboard.rewards', ['tab' => 'refer']))->assertOk()->getContent();

        $this->assertStringContainsString('1 person referred', $html);
        $this->assertStringContainsString('250 Rcoin</span> minted so far', $html);
        $this->assertStringContainsString('<span class="font-semibold text-zinc-900 dark:text-white">1</span> friend joined', $html);
        // The face on the strip is the top referrer's live profile picture.
        $this->assertStringContainsString($referrer->initialsAvatar(56), $html);
        $this->assertStringContainsString('/?ref=realref', $html);
    }

    /** A customer without a code gets one saved, so the link they share actually attributes sign-ups. */
    public function test_a_customer_without_a_code_gets_a_saved_working_code(): void
    {
        $user = User::factory()->create(['name' => 'Noel Nocode', 'referral_code' => null]);

        $html = $this->withoutVite()->actingAs($user)->get(route('dashboard.rewards', ['tab' => 'refer']))->assertOk()->getContent();

        $code = $user->fresh()->referral_code;
        $this->assertNotEmpty($code, 'The referral code was not saved.');
        $this->assertStringContainsString('/?ref='.$code, $html);

        // And that code really attributes a new sign-up.
        $friend = User::factory()->create();
        $this->assertNotNull(Referral::attribute($friend, $code));
    }

    /**
     * "Refer a product" sits below the card: the pinned picks first, each
     * carrying that product's own public page with the customer's ?ref= on it
     * and the real best-case reward. Every category with a public page can be
     * pinned; only gift cards and eSIMs fill the leftover slots by margin.
     */
    public function test_refer_a_product_cards_carry_the_pinned_picks_and_the_code(): void
    {
        Setting::set('rcoin_usd_rate', 0.005);
        Setting::set('referral_reward_percentage', 0.5);

        $giftCards = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $esims = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);
        $airtime = Category::create(['name' => 'Mobile Airtime', 'slug' => 'mobile-airtime', 'type' => 'digital']);

        $apple = Product::factory()->create([
            'brand_key' => 'Apple',
            'category_id' => $giftCards->id,
            'logo_url' => 'https://example.test/apple-card.png',
            'description' => 'Spend it on apps, games, music and more.',
        ]);
        ProductVariant::factory()->create(['product_id' => $apple->id, 'face_value' => 100, 'cost_price' => 60, 'retail_price' => 100]);

        // An eSIM links by its own slug, not by a brand page. A worldwide
        // plan: the region cards key on the WW country code, and the pinned
        // regions read those cards.
        $esim = Product::factory()->create([
            'brand_key' => null,
            'provider_name' => 'airalo',
            'country_code' => 'WW',
            'name' => 'Global Data eSIM',
            'slug' => 'esim-ww-global',
            'category_id' => $esims->id,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $esim->id,
            'face_value' => 100,
            'cost_price' => 70,
            'retail_price' => 100,
            'is_available' => true,
            'is_variable' => false,
            // A plan the region cards can describe.
            'metadata' => ['data_limit' => '1 GB', 'duration_days' => 7],
        ]);

        // Top-ups are out of scope for this row, however fat the margin.
        $mtn = Product::factory()->create([
            'brand_key' => 'MTN',
            'category_id' => $airtime->id,
            'logo_url' => 'https://example.test/mtn.png',
        ]);
        ProductVariant::factory()->create(['product_id' => $mtn->id, 'face_value' => 100, 'cost_price' => 10, 'retail_price' => 100]);

        $user = User::factory()->create(['referral_code' => 'prodref']);
        $html = $this->withoutVite()->actingAs($user)->get(route('dashboard.rewards', ['tab' => 'refer']))->assertOk()->getContent();

        $this->assertStringContainsString('Know what they need? Refer a product', $html);

        foreach ([
            route('shop.brand', ['brandSlug' => Product::brandSlug('Apple')]).'?ref=prodref',
            route('shop.esim', ['slug' => 'esim-ww-global']).'?ref=prodref',
        ] as $url) {
            // @js() json-encodes, so the URL sits in the attribute with escaped slashes.
            $this->assertStringContainsString(str_replace('/', '\/', $url), $html);
        }

        $cards = substr($html, strpos($html, 'Know what they need?'));

        // The pinned picks lead the row and take turns: eSIM region, then brand.
        $this->assertLessThan(strpos($cards, 'Apple'), strpos($cards, 'Global eSIM'));

        // The badge is the referral reward on the biggest denomination, worked
        // out for real: 0.5% of $100 = $0.50, and $0.50 / $0.005 = 100 Rcoin.
        $this->assertStringContainsString('Earn up to 100 Rcoin', $cards);

        // The gift tile repeats the storefront card's documented box.
        $this->assertStringContainsString('aspect-[16/10] items-center justify-center overflow-hidden rounded-[15px]', $cards);

        // Our own line about what the product is for, from config, ahead of the
        // supplier's inventory text.
        $this->assertStringContainsString(config('dashboard.refer_product_blurbs')['Apple'], $cards);
        $this->assertStringNotContainsString('Spend it on apps, games, music and more.', $cards);

        // Filter pills, styled from aria-pressed only so Alpine can move the state.
        $this->assertStringContainsString('aria-label="Filter products"', $cards);
        $this->assertStringContainsString('.refer-pill[aria-pressed="true"] { background-color: #0c1337;', $cards);

        // A top-up brand appears only when config pins it: MTN is pinned, so it
        // gets a card and links to its own page type; the margin fill still
        // never reaches into that category on its own.
        $topupUrl = url('/').parse_url(route('shop.topup', ['brandSlug' => Product::brandSlug('MTN')]), PHP_URL_PATH).'?ref=prodref';
        $this->assertStringContainsString(str_replace('/', '\/', $topupUrl), $html);

        // The New pill is always offered, so a product added later has a home.
        $this->assertStringContainsString('>New</button>', preg_replace('/\s+/', '', $cards) ?? '');
    }

    /** The tiles feature Apple, Amazon, Sephora and Discord, in that order. */
    public function test_the_logo_tiles_feature_the_configured_gift_card_brands(): void
    {
        Product::factory()->create(['brand_key' => 'Sephora', 'logo_url' => 'https://example.test/sephora-card.png', 'is_active' => true]);

        $html = $this->page('?tab=refer');
        $start = strpos($html, 'id="partners-panel-refer"');
        $panel = substr($html, $start, strpos($html, 'id="partners-panel-rcoin"') - $start);

        $last = -1;
        foreach ([asset('assets/apple.webp'), asset('assets/amazon.webp'), 'https://example.test/sephora-card.png', asset('assets/discord-nitro.webp')] as $logo) {
            $at = strpos($panel, 'src="'.$logo.'"');
            $this->assertNotFalse($at, $logo.' is missing from the tiles.');
            $this->assertGreaterThan($last, $at, $logo.' is out of order.');
            $last = $at;
        }
    }

    /** The five biggest referrers show as avatars; the rest collapse into "+N". */
    public function test_the_avatar_stack_shows_five_top_referrers_then_a_plus_count(): void
    {
        $viewer = User::factory()->create(['referral_code' => 'manyref']);
        foreach (range(1, 8) as $i) {
            Referral::create([
                'referrer_id' => User::factory()->create(['name' => "Referrer {$i}"])->id,
                'referred_user_id' => User::factory()->create(['name' => "Friend {$i}"])->id,
                'status' => 'active',
                'total_rewards_generated' => 0,
                'total_orders_completed' => 0,
            ]);
        }

        $html = $this->withoutVite()->actingAs($viewer)->get(route('dashboard.rewards', ['tab' => 'refer']))->assertOk()->getContent();
        $bar = substr($html, strpos($html, 'people referred') - 4000, 4000);

        $this->assertSame(5, substr_count($bar, 'src="data:image/svg+xml;base64,'));
        $this->assertStringContainsString('>+3</span>', $bar);
        $this->assertStringContainsString('8 people referred', $html);
    }
}
