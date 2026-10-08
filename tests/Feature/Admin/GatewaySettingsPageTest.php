<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Payment\Services\PaymentEligibilityService;
use App\Domain\Payment\Support\FiatGatewayRouter;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Models\Admin;
use App\Models\PaymentRoute;
use App\Models\SiteSetting;
use App\Support\FeatureFlag;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Management > Gateway settings: the gateway switches, the payment routing and
 * the eligibility grid on one page, with every change audited and every change
 * that would leave a method with nobody able to take it refused.
 */
class GatewaySettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(AdminSeeder::class);
        config([
            'services.korapay.secret_key' => 'sk_test_korapay_page',
            'services.paystack.secret_key' => 'sk_test_paystack_page',
        ]);

        $this->admin = Admin::firstOrCreate(
            ['email' => 'gateways-super@example.test'],
            ['name' => 'Gateways Super', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
    }

    private function route(string $currency, string $method): PaymentRoute
    {
        return PaymentRoute::where('currency', $currency)->where('method', $method)->sole();
    }

    // ------------------------------------------------------------- the page

    public function test_the_page_renders_all_three_tabs_for_a_super_admin(): void
    {
        $this->actingAs($this->admin, 'admin')->withoutVite();

        $this->get(route('admin.management.gateways'))
            ->assertOk()
            ->assertSee('Gateway settings')
            ->assertSee('Flutterwave')
            ->assertSee('Kora Pay');

        $this->get(route('admin.management.gateways', ['tab' => 'routing', 'currency' => 'XAF']))
            ->assertOk()
            ->assertSee('Mobile money')
            ->assertSee('Taking payments now');

        $this->get(route('admin.management.gateways', ['tab' => 'eligibility']))
            ->assertOk()
            ->assertSee('Enforce eligibility');
    }

    /**
     * Outcomes go to the admin layout's toast, which fades on its own, and
     * every switch asks through the shared confirm modal, not the browser.
     */
    public function test_outcomes_toast_and_switches_confirm_in_the_shared_modal(): void
    {
        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.management.gateways')
            ->call('toggleGateway', 'korapay', true)
            ->assertDispatched('toast', message: 'Saved.');

        $route = $this->route('NGN', 'bank_qr');
        Volt::test('admin.management.gateways', ['tab' => 'routing', 'currency' => 'NGN'])
            ->call('toggleGatewayOnRoute', $route->id, 'flutterwave', false)
            ->assertDispatched('toast', isError: true);

        $this->withoutVite()->get(route('admin.management.gateways', ['tab' => 'routing', 'currency' => 'NGN']))
            ->assertOk()
            ->assertSee('data-confirm-title="Stop offering Card in NGN?"', false)
            ->assertDontSee('wire:confirm', false);
    }

    /** The descriptions and the markets on offer come from the capability table, not from prose. */
    public function test_the_page_describes_each_gateway_from_the_capability_table(): void
    {
        $this->actingAs($this->admin, 'admin')->withoutVite();

        $this->get(route('admin.management.gateways'))
            ->assertOk()
            ->assertSee('mobile money in Cameroon (MTN, Orange)', false)
            ->assertDontSee('GHS/KES/XOF mobile money', false)
            ->assertSee('Cards, Apple Pay, bank transfer, USSD in NGN', false);

        $this->get(route('admin.management.gateways', ['tab' => 'routing', 'currency' => 'XOF']))
            ->assertOk()
            ->assertSee('On offer now:', false)
            ->assertSee('Côte d&#039;Ivoire', false)
            ->assertSee('(MTN, Orange, Moov, Wave)', false);
    }

    /** The new markets are on the Routing tab from day one, switched off and saying why. */
    public function test_untested_markets_appear_on_the_routing_tab_switched_off(): void
    {
        $this->actingAs($this->admin, 'admin')->withoutVite();

        $this->get(route('admin.management.gateways', ['tab' => 'routing', 'currency' => 'EGP']))
            ->assertOk()
            ->assertSee('EGP')
            ->assertSee('TZS')
            ->assertSee(SupportedPaymentMethods::OFF_UNTIL_TESTED_NOTE);
    }

    public function test_an_admin_without_the_management_grant_is_kept_out(): void
    {
        $scoped = Admin::firstOrCreate(
            ['email' => 'gateways-scoped@example.test'],
            ['name' => 'Gateways Scoped', 'password' => 'password', 'role' => AdminRole::Admin, 'is_active' => true],
        );

        $response = $this->actingAs($scoped, 'admin')->withoutVite()->get(route('admin.management.gateways'));

        $this->assertContains($response->status(), [403, 404], 'a scoped admin reached gateway settings');
    }

    public function test_the_management_menu_links_to_it(): void
    {
        $this->actingAs($this->admin, 'admin')->withoutVite();

        $this->get(route('admin.management.monitoring'))
            ->assertOk()
            ->assertSee(route('admin.management.gateways'), false);
    }

    /**
     * Livewire actions post to Livewire's endpoint, which AdminAuth never
     * sees; the page checks the admin itself on every request.
     */
    public function test_its_actions_refuse_an_admin_without_the_grant_or_switched_off(): void
    {
        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');

        $scoped = Admin::firstOrCreate(
            ['email' => 'gateways-scoped@example.test'],
            ['name' => 'Gateways Scoped', 'password' => 'password', 'role' => AdminRole::Admin, 'is_active' => true],
        );
        $this->actingAs($scoped, 'admin');
        Volt::test('admin.management.gateways')->assertForbidden();

        // Open while allowed, switched off before the click: the click is refused.
        $this->actingAs($this->admin, 'admin');
        $page = Volt::test('admin.management.gateways')->assertOk();

        $this->admin->update(['is_active' => false]);
        $page->call('toggleGateway', 'korapay', true)->assertForbidden();

        $this->assertSame('off', SiteSetting::query()->where('key', 'features.gateway_korapay_enabled')->value('value'));
    }

    // ------------------------------------------------------------- gateways

    public function test_switching_a_gateway_writes_its_flag_and_audits_it(): void
    {
        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.management.gateways')->call('toggleGateway', 'korapay', true)->assertSet('refusal', null);

        $this->assertTrue(FeatureFlag::gatewayEnabled('korapay'));
        $this->assertTrue(
            AuditLog::where('action', 'setting.updated')->get()
                ->contains(fn (AuditLog $log) => ($log->metadata['key'] ?? null) === 'features.gateway_korapay_enabled'),
        );
    }

    /** A gateway with no keys on the server cannot be switched on: it would refuse every customer. */
    public function test_a_gateway_without_keys_cannot_be_switched_on(): void
    {
        config(['services.korapay.secret_key' => null]);
        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.management.gateways')
            ->call('toggleGateway', 'korapay', true)
            ->assertSet('refusal', 'Kora Pay has no API keys configured, so it cannot be switched on. Add its keys to the server first.');

        $this->assertFalse(FeatureFlag::gatewayEnabled('korapay'));

        // Switching OFF never needs keys: the kill switch must always work.
        SiteSetting::put('features.gateway_korapay_enabled', 'on', 'features');
        Volt::test('admin.management.gateways')->call('toggleGateway', 'korapay', false)->assertSet('refusal', null);
        $this->assertFalse(FeatureFlag::gatewayEnabled('korapay'));
    }

    /** A double click, or a second tab, repeats the choice instead of undoing it. */
    public function test_switching_a_gateway_on_twice_leaves_it_on(): void
    {
        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.management.gateways')
            ->call('toggleGateway', 'korapay', true)
            ->call('toggleGateway', 'korapay', true);

        $this->assertTrue(FeatureFlag::gatewayEnabled('korapay'));
    }

    // -------------------------------------------------------------- routing

    /** Kora first for XAF mobile money, the hackathon's headline move. */
    public function test_putting_kora_first_for_xaf_mobile_money_routes_to_it_once_kora_is_on(): void
    {
        SiteSetting::put('features.gateway_korapay_enabled', 'on', 'features');
        $this->actingAs($this->admin, 'admin');
        $route = $this->route('XAF', 'mobile_money');

        $this->assertSame(['flutterwave', 'korapay'], $route->gateways);

        Volt::test('admin.management.gateways', ['tab' => 'routing'])
            ->call('moveGateway', $route->id, 'korapay', 'up')
            ->assertSet('refusal', null);

        $this->assertSame(['korapay', 'flutterwave'], $route->fresh()->gateways);
        $this->assertSame('korapay', FiatGatewayRouter::resolve('mobile_money', 'XAF'));
        $this->assertSame($this->admin->id, $route->fresh()->updated_by);

        $log = AuditLog::where('action', 'payment_route.updated')->sole();
        $this->assertSame(['flutterwave', 'korapay'], $log->before_state['gateways']);
        $this->assertSame(['korapay', 'flutterwave'], $log->after_state['gateways']);
    }

    /** Paystack cannot charge XAF mobile money; the page will not pretend it can. */
    public function test_adding_a_gateway_that_cannot_take_the_method_is_refused(): void
    {
        $this->actingAs($this->admin, 'admin');
        $route = $this->route('UGX', 'mobile_money');
        $route->update(['gateways' => ['flutterwave']]);

        Volt::test('admin.management.gateways', ['tab' => 'routing'])
            ->call('toggleGatewayOnRoute', $route->id, 'paystack', true)
            ->assertSet('refusal', 'Paystack cannot take Mobile money in UGX.');

        $this->assertSame(['flutterwave'], $route->fresh()->gateways);
    }

    public function test_removing_the_last_gateway_able_to_take_it_is_refused(): void
    {
        $this->actingAs($this->admin, 'admin');
        $route = $this->route('NGN', 'bank_qr');

        Volt::test('admin.management.gateways', ['tab' => 'routing'])
            ->call('toggleGatewayOnRoute', $route->id, 'flutterwave', false)
            ->assertSet('refusal', 'Refused: no gateway left on this row is switched on and able to charge it. Keep at least one, or switch one on first.');

        $this->assertSame(['flutterwave'], $route->fresh()->gateways);
        $this->assertSame(0, AuditLog::where('action', 'payment_route.updated')->count());
    }

    /**
     * Able is not enough: XAF mobile money lists Kora and Paystack behind
     * Flutterwave, but Kora is off and Paystack cannot take XAF, so removing
     * Flutterwave would grey the tile out for every Cameroon customer.
     */
    public function test_removing_the_last_switched_on_gateway_is_refused_until_another_is_on(): void
    {
        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');
        $this->actingAs($this->admin, 'admin');
        $route = $this->route('XAF', 'mobile_money');

        Volt::test('admin.management.gateways', ['tab' => 'routing', 'currency' => 'XAF'])
            ->call('toggleGatewayOnRoute', $route->id, 'flutterwave', false)
            ->assertSet('refusal', 'Refused: no gateway left on this row is switched on and able to charge it. Keep at least one, or switch one on first.');

        $this->assertSame(['flutterwave', 'korapay'], $route->fresh()->gateways);

        SiteSetting::put('features.gateway_korapay_enabled', 'on', 'features');

        Volt::test('admin.management.gateways', ['tab' => 'routing', 'currency' => 'XAF'])
            ->call('toggleGatewayOnRoute', $route->id, 'flutterwave', false)
            ->assertSet('refusal', null);

        $this->assertSame(['korapay'], $route->fresh()->gateways);
        $this->assertSame('korapay', FiatGatewayRouter::resolve('mobile_money', 'XAF'));
    }

    /** Switching a method on runs the same guard as editing its gateways. */
    public function test_a_method_cannot_be_switched_on_with_no_switched_on_gateway(): void
    {
        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');
        $this->actingAs($this->admin, 'admin');
        $route = $this->route('NGN', 'ussd');
        $route->update(['is_offered' => false, 'gateways' => ['paystack']]);

        Volt::test('admin.management.gateways', ['tab' => 'routing', 'currency' => 'NGN'])
            ->call('toggleOffered', $route->id, true)
            ->assertSet('refusal', 'Refused: no gateway left on this row is switched on and able to charge it. Keep at least one, or switch one on first.');

        $this->assertFalse($route->fresh()->is_offered);
    }

    /** The confirm copy says what really happens: who takes over, or that nothing can. */
    public function test_the_confirm_copy_names_the_gateway_that_takes_over(): void
    {
        SiteSetting::put('features.gateway_korapay_enabled', 'on', 'features');
        $this->actingAs($this->admin, 'admin')->withoutVite();

        // XAF mobile money: Flutterwave first, Kora on behind it.
        $this->get(route('admin.management.gateways', ['tab' => 'routing', 'currency' => 'XAF']))
            ->assertOk()
            ->assertSee('Kora Pay takes new Mobile money payments in XAF instead.', false);

        // NGN bank QR: Flutterwave alone, so the remove button is disabled.
        $this->get(route('admin.management.gateways', ['tab' => 'routing', 'currency' => 'NGN']))
            ->assertOk()
            ->assertSee('Keep at least one switched-on gateway that can take Bank QR in NGN', false);

        // Gateways tab: switching Flutterwave off names the rows only it serves.
        $this->get(route('admin.management.gateways'))
            ->assertOk()
            ->assertSee('It is the only switched-on gateway for: ', false)
            ->assertSee('NGN Bank QR', false);
    }

    public function test_a_method_can_be_switched_off_with_a_reason(): void
    {
        $this->actingAs($this->admin, 'admin');
        $route = $this->route('NGN', 'ussd');

        Volt::test('admin.management.gateways', ['tab' => 'routing'])
            ->call('toggleOffered', $route->id, false)
            ->call('saveNote', $route->id, '  Flutterwave USSD is down for the week.  ');

        $route->refresh();
        $this->assertFalse($route->is_offered);
        $this->assertSame('Flutterwave USSD is down for the week.', $route->note);
        $this->assertSame(2, AuditLog::where('action', 'payment_route.updated')->count());
    }

    public function test_the_tile_order_can_be_changed(): void
    {
        $this->actingAs($this->admin, 'admin');
        $mobileMoney = $this->route('XAF', 'mobile_money');

        Volt::test('admin.management.gateways', ['tab' => 'routing'])
            ->call('moveMethod', $mobileMoney->id, 'up')
            ->assertSet('flash', 'Tile order saved.');

        $this->assertSame(
            ['mobile_money', 'card', 'crypto', 'wallet'],
            PaymentRoute::where('currency', 'XAF')->orderBy('sort_order')->pluck('method')->all(),
        );
        $this->assertTrue(AuditLog::where('action', 'payment_route.reordered')->exists());
    }

    /**
     * The edit starts from the row as stored under its lock, never from the
     * cached switchboard, so a stale cache cannot write an old order back.
     */
    public function test_a_gateway_edit_starts_from_the_stored_row_not_the_cache(): void
    {
        $this->actingAs($this->admin, 'admin');
        $route = $this->route('NGN', 'bank_transfer');

        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('bank_transfer', 'NGN'), 'prime the cache');
        DB::table('payment_routes')->where('id', $route->id)->update(['gateways' => json_encode(['flutterwave', 'korapay'])]);

        Volt::test('admin.management.gateways', ['tab' => 'routing', 'currency' => 'NGN'])
            ->call('moveGateway', $route->id, 'korapay', 'up')
            ->assertSet('refusal', null);

        $this->assertSame(['korapay', 'flutterwave'], $route->fresh()->gateways);
    }

    public function test_adding_a_gateway_already_on_the_row_changes_nothing(): void
    {
        $this->actingAs($this->admin, 'admin');
        $route = $this->route('XAF', 'mobile_money');

        Volt::test('admin.management.gateways', ['tab' => 'routing'])
            ->call('toggleGatewayOnRoute', $route->id, 'korapay', true)
            ->assertSet('refusal', null);

        $this->assertSame(['flutterwave', 'korapay'], $route->fresh()->gateways);
        $this->assertSame(0, AuditLog::where('action', 'payment_route.updated')->count());
    }

    public function test_switching_off_the_last_method_a_currency_offers_is_refused(): void
    {
        $this->actingAs($this->admin, 'admin');
        PaymentRoute::where('currency', 'EUR')->where('method', '!=', 'card')->update(['is_offered' => false]);
        $card = $this->route('EUR', 'card');

        Volt::test('admin.management.gateways', ['tab' => 'routing', 'currency' => 'EUR'])
            ->call('toggleOffered', $card->id, false)
            ->assertSet('refusal', 'Refused: this is the last method EUR offers. Keep at least one on.');

        $this->assertTrue($card->fresh()->is_offered);
    }

    public function test_switching_a_method_off_twice_leaves_it_off(): void
    {
        $this->actingAs($this->admin, 'admin');
        $route = $this->route('NGN', 'ussd');

        Volt::test('admin.management.gateways', ['tab' => 'routing', 'currency' => 'NGN'])
            ->call('toggleOffered', $route->id, false)
            ->call('toggleOffered', $route->id, false);

        $this->assertFalse($route->fresh()->is_offered);
        $this->assertSame(1, AuditLog::where('action', 'payment_route.updated')->count());
    }

    public function test_moving_the_top_tile_up_does_nothing_and_says_nothing(): void
    {
        $this->actingAs($this->admin, 'admin');
        $card = $this->route('XAF', 'card');

        Volt::test('admin.management.gateways', ['tab' => 'routing'])
            ->call('moveMethod', $card->id, 'up')
            ->assertSet('flash', null);

        $this->assertFalse(AuditLog::where('action', 'payment_route.reordered')->exists());
    }

    // ---------------------------------------------------------- eligibility

    /** The interlock moved with the switch: enforcing with nothing approved is refused here too. */
    public function test_enforcing_eligibility_with_no_cell_approved_is_refused(): void
    {
        SiteSetting::query()->where('key', 'like', 'features.eligibility_%')->update(['value' => json_encode('off')]);
        SiteSetting::put('features.payment_eligibility_enforce_enabled', 'off', 'features');
        Cache::flush();
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.management.gateways', ['tab' => 'eligibility'])
            ->call('toggleEnforcement', true)
            ->assertSet('refusal', 'Refused: no eligibility cell is approved yet. Approve at least one gateway x category cell before enforcing, or every cart would be refused.');

        $this->assertFalse(app(PaymentEligibilityService::class)->enforcementEnabled());
    }

    public function test_an_eligibility_cell_can_be_flipped(): void
    {
        SiteSetting::put('features.eligibility_korapay_esim_enabled', 'off', 'features');
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.management.gateways', ['tab' => 'eligibility'])->call('toggleCell', 'korapay', 'esim', true);

        $this->assertSame('on', SiteSetting::query()->where('key', 'features.eligibility_korapay_esim_enabled')->value('value'));
    }

    // ------------------------------------------------- system settings handoff

    public function test_system_settings_no_longer_lists_or_saves_the_moved_switches(): void
    {
        $this->actingAs($this->admin, 'admin')->withoutVite();

        $this->get(route('admin.settings'))
            ->assertOk()
            ->assertDontSee('features.gateway_korapay_enabled')
            ->assertDontSee('features.eligibility_korapay_esim_enabled')
            ->assertSee(route('admin.management.gateways'), false);

        SiteSetting::put('features.gateway_korapay_enabled', 'off', 'features');

        Volt::test('admin.system-settings')
            ->call('updateSetting', 'features.gateway_korapay_enabled', 'on')
            ->assertHasErrors(['settings.features.gateway_korapay_enabled']);

        $this->assertFalse(FeatureFlag::gatewayEnabled('korapay'));
    }

    public function test_system_settings_still_saves_its_own_switches(): void
    {
        SiteSetting::put('features.checkout_enabled', 'on', 'features');
        $this->actingAs($this->admin, 'admin');

        Volt::test('admin.system-settings')
            ->call('updateSetting', 'features.checkout_enabled', 'off')
            ->assertHasNoErrors();

        $this->assertFalse(FeatureFlag::on('checkout'));
    }
}
