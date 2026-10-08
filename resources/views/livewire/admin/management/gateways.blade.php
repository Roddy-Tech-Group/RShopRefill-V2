{{-- FILE: resources/views/livewire/admin/management/gateways.blade.php --}}
<?php

use App\Domain\Admin\Services\FeatureSettingWriter;
use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Payment\Services\PaymentEligibilityService;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Support\FiatGatewayRouter;
use App\Domain\Payment\Support\MobileMoneyRail;
use App\Domain\Payment\Support\GatewayCapabilities;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\Shared\Enums\ComplianceCategory;
use App\Domain\Shared\Enums\PaymentGateway;
use App\Models\Admin;
use App\Models\PaymentRoute;
use App\Models\SiteSetting;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/*
 * Gateway settings: everything that decides who may take a payment, on one page.
 *
 *   Gateways     each gateway's on/off switch and which keys it is running on
 *   Routing      per currency and method: offered or not, tile order, and the
 *                gateways allowed to take it, first choice first
 *   Eligibility  which product categories each gateway may be charged for
 *
 * The switches are the same feature flags they always were; only the screen
 * moved. Every change is audited, and the routing refuses an order no gateway
 * on it can actually charge.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Gateway settings')]
class extends Component {
    private const TABS = ['gateways', 'eligibility', 'routing'];

    private const METHOD_LABELS = [
        'card' => 'Card',
        'apple_pay' => 'Apple Pay',
        'bank_transfer' => 'Bank transfer',
        'pay_with_bank' => 'Pay with bank',
        'ussd' => 'USSD',
        'bank_qr' => 'Bank QR',
        'mobile_wallet' => 'Mobile wallet',
        'mobile_money' => 'Mobile money',
        'crypto' => 'Crypto',
        'wallet' => 'Wallet balance',
    ];

    #[Url(history: true, except: 'gateways')]
    public string $tab = 'gateways';

    #[Url(history: true, except: '')]
    public string $currency = '';

    /** The last action's outcome; shown through the admin layout's toast. */
    public ?string $flash = null;

    public ?string $refusal = null;

    /**
     * Livewire actions post to Livewire's own endpoint, not this route, so the
     * AdminAuth middleware never sees them. Check the admin on every request.
     */
    public function boot(): void
    {
        $admin = auth('admin')->user();

        abort_unless(
            $admin instanceof Admin && $admin->is_active && $admin->canAccessAdminRoute('admin.management.gateways'),
            403,
        );
    }

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'gateways';
        }

        $currencies = $this->currencies();

        if (! in_array($this->currency, $currencies, true)) {
            $this->currency = $currencies[0] ?? '';
        }
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'gateways';
        $this->reset('flash', 'refusal');
    }

    public function chooseCurrency(string $currency): void
    {
        if (in_array($currency, $this->currencies(), true)) {
            $this->currency = $currency;
            $this->reset('flash', 'refusal');
        }
    }

    // ------------------------------------------------------------ gateways

    /**
     * @return list<array{key: string, label: string, on: bool, implemented: bool, keys: string, role: string}>
     */
    #[Computed]
    public function gateways(): array
    {
        // What each gateway can take, read off the same table the router uses
        // (GatewayCapabilities), so this screen can never promise more than
        // checkout will route.
        $roles = [
            'flutterwave' => GatewayCapabilities::summary('flutterwave'),
            'paystack' => GatewayCapabilities::summary('paystack'),
            'korapay' => GatewayCapabilities::summary('korapay'),
            'nowpayments' => 'Crypto',
            'wallet' => 'Customer wallet balance',
        ];

        $rows = [];

        foreach (array_keys(FeatureFlag::GATEWAY_FLAGS) as $gateway) {
            $rows[] = [
                'key' => $gateway,
                'label' => PaymentGateway::tryFrom($gateway)?->label() ?? ucfirst($gateway),
                'on' => FeatureFlag::gatewayEnabled($gateway),
                'implemented' => $gateway === 'wallet' || PaymentGatewayFactory::isImplemented($gateway),
                'keys' => $this->keyMode($gateway),
                'role' => $roles[$gateway] ?? '',
                'alone_on' => $this->rowsOnlyThisGatewayServes($gateway),
            ];
        }

        return $rows;
    }

    /**
     * Offered fiat rows where this gateway is the only one that is both
     * switched on and able to charge them. Switching it off greys those
     * tiles out for customers, so the confirm box says so by name.
     *
     * @return list<string>
     */
    private function rowsOnlyThisGatewayServes(string $gateway): array
    {
        if (! FeatureFlag::gatewayEnabled($gateway)) {
            return [];
        }

        $rows = [];

        foreach (PaymentRoute::query()->where('is_offered', true)->orderBy('currency')->orderBy('sort_order')->get() as $route) {
            if (! array_key_exists($route->method, FiatGatewayRouter::METHOD_PRIORITY)) {
                continue;
            }

            $serving = $this->liveAbleGateways($route, $this->rowOrder($route));

            if ($serving === [$gateway]) {
                $rows[] = $route->currency.' '.(self::METHOD_LABELS[$route->method] ?? $route->method);
            }
        }

        return $rows;
    }

    /**
     * Set a gateway on or off. The view sends the state it offered the admin,
     * so a double click or a stale tab repeats the choice instead of undoing it.
     */
    public function toggleGateway(string $gateway, bool $on): void
    {
        $flag = FeatureFlag::GATEWAY_FLAGS[$gateway] ?? null;

        if ($flag === null) {
            return;
        }

        if ($on && $gateway !== 'wallet' && ! PaymentGatewayFactory::isImplemented($gateway)) {
            $this->refuse('That gateway is not built yet, so it cannot be switched on.');

            return;
        }

        // A gateway switched on with no keys answers every customer with an
        // error and falls back to nothing; one on test keys in production
        // releases real goods against sandbox money. Neither is a switch.
        if ($on && $gateway !== 'wallet') {
            $label = PaymentGateway::tryFrom($gateway)?->label() ?? ucfirst($gateway);
            $keys = $this->keyMode($gateway);

            if ($keys === 'No keys') {
                $this->refuse("{$label} has no API keys configured, so it cannot be switched on. Add its keys to the server first.");

                return;
            }

            if ($keys === 'Test keys' && app()->isProduction()) {
                $this->refuse("{$label} is running on test keys. Put its live keys on the server before switching it on in production.");

                return;
            }
        }

        $this->writeSetting('features.'.$flag.'_enabled', $on ? 'on' : 'off');
    }

    /**
     * Which keys the gateway would charge with: live money, the sandbox, or
     * none at all. Read from the configured secret, never shown.
     */
    private function keyMode(string $gateway): string
    {
        if ($gateway === 'wallet') {
            return 'n/a';
        }

        if ($gateway === 'nowpayments') {
            $key = (string) config('services.nowpayments.api_key');

            return $key === '' ? 'No keys' : (config('services.nowpayments.sandbox') ? 'Test keys' : 'Live keys');
        }

        $key = (string) config("services.{$gateway}.secret_key");

        if ($key === '') {
            return 'No keys';
        }

        return preg_match('/test/i', $key) === 1 ? 'Test keys' : 'Live keys';
    }

    // ------------------------------------------------------------- routing

    /**
     * @return list<string>
     */
    public function currencies(): array
    {
        return PaymentRoute::query()->distinct()->orderBy('currency')->pluck('currency')->all();
    }

    /**
     * The selected currency's rows, each with what the router would do right now.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function routes(): array
    {
        $rows = [];

        $routes = PaymentRoute::query()
            ->with('updatedBy:id,name')
            ->where('currency', $this->currency)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($routes as $route) {
            $isFiat = array_key_exists($route->method, FiatGatewayRouter::METHOD_PRIORITY);

            $gateways = [];

            if ($isFiat) {
                // The row's order first, then any fiat gateway not on it, so the
                // admin can add one; each marked with whether it can charge this.
                $listed = $this->rowOrder($route);
                $others = array_values(array_diff(FiatGatewayRouter::FIAT_GATEWAYS, $listed));

                $live = $this->liveAbleGateways($route, $listed);

                foreach ([...$listed, ...$others] as $gateway) {
                    // Who takes this row's new payments if this gateway is removed:
                    // the first remaining gateway that is switched on and able.
                    $remaining = array_values(array_diff($live, [$gateway]));

                    $gateways[] = [
                        'key' => $gateway,
                        'label' => PaymentGateway::tryFrom($gateway)?->label() ?? ucfirst($gateway),
                        'listed' => in_array($gateway, $listed, true),
                        'able' => FiatGatewayRouter::supports($gateway, $route->method, $route->currency),
                        'on' => FeatureFlag::gatewayEnabled($gateway),
                        'successor' => isset($remaining[0]) ? (PaymentGateway::tryFrom($remaining[0])?->label() ?? ucfirst($remaining[0])) : null,
                    ];
                }
            }

            $rows[] = [
                'id' => $route->id,
                'method' => $route->method,
                'label' => self::METHOD_LABELS[$route->method] ?? ucfirst(str_replace('_', ' ', $route->method)),
                'offered' => (bool) $route->is_offered,
                'note' => $route->note,
                'fiat' => $isFiat,
                'gateways' => $gateways,
                'taking' => $isFiat ? FiatGatewayRouter::resolve($route->method, $route->currency) : null,
                // Mobile money: the countries and networks customers can use right
                // now, the union of what the switched-on gateways on this row take.
                'coverage' => $route->method === 'mobile_money' && $route->is_offered
                    ? MobileMoneyRail::coverageOffered($route->currency)
                    : [],
                'updated' => $route->updatedBy?->name,
            ];
        }

        return $rows;
    }

    public function toggleOffered(int $routeId, bool $offered): void
    {
        $this->changeRoute($routeId, function (PaymentRoute $route) use ($offered): ?string {
            if (! $offered && $route->is_offered) {
                $othersOffered = PaymentRoute::query()
                    ->where('currency', $route->currency)
                    ->whereKeyNot($route->id)
                    ->where('is_offered', true)
                    ->lockForUpdate()
                    ->count();

                if ($othersOffered === 0) {
                    return "Refused: this is the last method {$route->currency} offers. Keep at least one on.";
                }
            }

            $route->is_offered = $offered;

            return $this->refusalForOrder($route);
        });
    }

    public function saveNote(int $routeId, string $note): void
    {
        $this->changeRoute($routeId, function (PaymentRoute $route) use ($note): ?string {
            $note = trim($note);
            $route->note = $note === '' ? null : mb_substr($note, 0, 255);

            return null;
        });
    }

    public function moveMethod(int $routeId, string $direction): void
    {
        $moved = DB::transaction(function () use ($routeId, $direction): bool {
            $route = PaymentRoute::query()->lockForUpdate()->find($routeId);

            if ($route === null) {
                return false;
            }

            $siblings = PaymentRoute::query()
                ->where('currency', $route->currency)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            $index = $siblings->search(fn (PaymentRoute $row) => $row->id === $route->id);
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || ! isset($siblings[$swapWith])) {
                return false;
            }

            // Renumber the whole currency in tens with the pair swapped, so a
            // tie or a gap in the old numbering can never leave two rows fighting
            // for the same place.
            $ordered = $siblings->all();
            [$ordered[$index], $ordered[$swapWith]] = [$ordered[$swapWith], $ordered[$index]];

            foreach ($ordered as $position => $row) {
                $sortOrder = ($position + 1) * 10;

                if ($row->sort_order !== $sortOrder) {
                    $row->sort_order = $sortOrder;
                    $row->updated_by = auth('admin')->id();
                    $row->save();
                }
            }

            app(AuditLogService::class)->log(
                action: 'payment_route.reordered',
                model: $route,
                metadata: ['currency' => $route->currency, 'method' => $route->method, 'direction' => $direction],
            );

            return true;
        });

        if ($moved) {
            $this->done('Tile order saved.');
        }
    }

    public function moveGateway(int $routeId, string $gateway, string $direction): void
    {
        $this->changeRoute($routeId, function (PaymentRoute $route) use ($gateway, $direction): ?string {
            $order = $this->rowOrder($route);
            $index = array_search($gateway, $order, true);
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || ! isset($order[$swapWith])) {
                return null;
            }

            [$order[$index], $order[$swapWith]] = [$order[$swapWith], $order[$index]];
            $route->gateways = $order;

            return $this->refusalForOrder($route);
        });
    }

    public function toggleGatewayOnRoute(int $routeId, string $gateway, bool $listed): void
    {
        $this->changeRoute($routeId, function (PaymentRoute $route) use ($gateway, $listed): ?string {
            if (! in_array($gateway, FiatGatewayRouter::FIAT_GATEWAYS, true)) {
                return 'Only card and bank gateways can be added to this row.';
            }

            $order = $this->rowOrder($route);

            if (in_array($gateway, $order, true) === $listed) {
                return null;
            }

            if (! $listed) {
                $order = array_values(array_diff($order, [$gateway]));
            } else {
                if (! FiatGatewayRouter::supports($gateway, $route->method, $route->currency)) {
                    $label = PaymentGateway::tryFrom($gateway)?->label() ?? $gateway;

                    return "{$label} cannot take ".(self::METHOD_LABELS[$route->method] ?? $route->method)." in {$route->currency}.";
                }

                $order[] = $gateway;
            }

            $route->gateways = $order;

            return $this->refusalForOrder($route);
        });
    }

    /**
     * The row's own gateway order as stored, fiat gateways only, read from the
     * locked row rather than the cached switchboard.
     *
     * @return list<string>
     */
    private function rowOrder(PaymentRoute $route): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', (array) $route->gateways),
            fn (string $gateway): bool => in_array($gateway, FiatGatewayRouter::FIAT_GATEWAYS, true),
        )));
    }

    /**
     * An offered fiat row must keep at least one gateway that is switched on
     * AND able to charge it, or the tile greys out for every customer the
     * moment the change saves. A row switched off may be edited freely: it
     * offers nothing until it is switched on, and switching on runs this
     * same check.
     */
    private function refusalForOrder(PaymentRoute $route): ?string
    {
        if (! array_key_exists($route->method, FiatGatewayRouter::METHOD_PRIORITY) || ! $route->is_offered) {
            return null;
        }

        if ($this->liveAbleGateways($route, $this->rowOrder($route)) !== []) {
            return null;
        }

        return 'Refused: no gateway left on this row is switched on and able to charge it. Keep at least one, or switch one on first.';
    }

    /**
     * The gateways in $order that are switched on, built, and able to charge
     * this row, in row order.
     *
     * @param  list<string>  $order
     * @return list<string>
     */
    private function liveAbleGateways(PaymentRoute $route, array $order): array
    {
        $live = FiatGatewayRouter::liveFiatGateways();

        return array_values(array_filter(
            $order,
            fn (string $gateway): bool => in_array($gateway, $live, true)
                && FiatGatewayRouter::supports($gateway, $route->method, $route->currency),
        ));
    }

    /**
     * Apply a change to one row under a lock, refuse it if the change says why,
     * otherwise save and audit it before and after.
     */
    private function changeRoute(int $routeId, Closure $change): void
    {
        $refusal = DB::transaction(function () use ($routeId, $change): ?string {
            $route = PaymentRoute::query()->lockForUpdate()->find($routeId);

            if ($route === null) {
                return 'That row no longer exists.';
            }

            $before = $route->only(['is_offered', 'gateways', 'note']);

            if ($why = $change($route)) {
                return $why;
            }

            if (! $route->isDirty()) {
                return null;
            }

            $route->updated_by = auth('admin')->id();
            $route->save();

            app(AuditLogService::class)->log(
                action: 'payment_route.updated',
                model: $route,
                beforeState: $before,
                afterState: $route->only(['is_offered', 'gateways', 'note']),
                metadata: ['currency' => $route->currency, 'method' => $route->method],
            );

            return null;
        });

        $refusal === null ? $this->done('Saved.') : $this->refuse($refusal);
    }

    // --------------------------------------------------------- eligibility

    /**
     * Category rows by gateway columns, from the cells that exist.
     *
     * @return array{enforced: bool, gateways: list<array{key: string, label: string}>, rows: list<array{category: string, label: string, cells: array<string, ?bool>}>}
     */
    #[Computed]
    public function eligibility(): array
    {
        $gateways = array_map(
            fn (PaymentGateway $gateway) => ['key' => $gateway->value, 'label' => $gateway->label()],
            PaymentGateway::cases(),
        );

        $values = SiteSetting::query()
            ->where('key', 'like', 'features.eligibility_%')
            ->pluck('value', 'key')
            ->all();

        $rows = [];

        foreach (ComplianceCategory::cases() as $category) {
            $cells = [];
            $any = false;

            foreach ($gateways as $gateway) {
                $key = "features.eligibility_{$gateway['key']}_{$category->value}_enabled";

                if (array_key_exists($key, $values)) {
                    $any = true;
                    $cells[$gateway['key']] = in_array(strtolower((string) $values[$key]), ['on', 'true', '1'], true);
                } else {
                    $cells[$gateway['key']] = null;
                }
            }

            if ($any) {
                $rows[] = ['category' => $category->value, 'label' => $category->label(), 'cells' => $cells];
            }
        }

        return [
            'enforced' => app(PaymentEligibilityService::class)->enforcementEnabled(),
            'gateways' => $gateways,
            'rows' => $rows,
        ];
    }

    public function toggleEnforcement(bool $on): void
    {
        $this->writeSetting('features.payment_eligibility_enforce_enabled', $on ? 'on' : 'off');
    }

    public function toggleCell(string $gateway, string $category, bool $on): void
    {
        $key = "features.eligibility_{$gateway}_{$category}_enabled";

        // Only a cell that already exists can be set; this never mints a new key.
        if (! SiteSetting::query()->where('key', $key)->exists()) {
            return;
        }

        $this->writeSetting($key, $on ? 'on' : 'off');
    }

    // -------------------------------------------------------------- shared

    private function writeSetting(string $key, string $value): void
    {
        $refusal = app(FeatureSettingWriter::class)->write($key, $value);

        $refusal === null ? $this->done('Saved.') : $this->refuse($refusal);
    }

    private function done(string $message): void
    {
        $this->flash = $message;
        $this->refusal = null;
        $this->dispatch('toast', message: $message);
        unset($this->gateways, $this->routes, $this->eligibility);
    }

    private function refuse(string $message): void
    {
        $this->refusal = $message;
        $this->flash = null;
        $this->dispatch('toast', message: $message, isError: true);
        unset($this->gateways, $this->routes, $this->eligibility);
    }
}; ?>

@php
    $card = 'rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]';
    $pill = fn (bool $active) => $active
        ? 'rounded-[12px] px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white'
        : 'rounded-[12px] px-3 py-1.5 text-xs font-semibold bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]';
    $switch = fn (bool $on) => $on ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-700';
    $knob = fn (bool $on) => $on ? 'translate-x-5' : 'translate-x-0.5';
@endphp

<div class="flex flex-col gap-5">
    <x-slot:heading>Gateway settings</x-slot:heading>
    <x-slot:subheading>Every payment goes to the best gateway that is on, with the next one ready behind it.</x-slot:subheading>

    <div class="flex flex-wrap items-center gap-1.5" role="tablist">
        @foreach (['gateways' => 'Gateways', 'eligibility' => 'Eligibility', 'routing' => 'Routing'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="show('{{ $key }}')" class="{{ $pill($tab === $key) }}">{{ $label }}</button>
        @endforeach
    </div>


    {{-- ───────────────────────────── Gateways ───────────────────────────── --}}
    @if ($tab === 'gateways')
        <section class="{{ $card }} !p-0 overflow-hidden" data-tab="gateways">
            <ul class="divide-y divide-zinc-100 dark:divide-white/5">
                @foreach ($this->gateways as $gateway)
                    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:gap-6">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ $gateway['label'] }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $gateway['role'] }}</p>
                        </div>
                        <span @class([
                            'shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $gateway['keys'] === 'Live keys',
                            'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $gateway['keys'] === 'Test keys',
                            'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-400' => ! in_array($gateway['keys'], ['Live keys', 'Test keys'], true),
                        ])>{{ $gateway['keys'] }}</span>
                        <div class="flex shrink-0 items-center gap-3">
                            <button
                                type="button"
                                role="switch"
                                aria-checked="{{ $gateway['on'] ? 'true' : 'false' }}"
                                aria-label="{{ $gateway['label'] }} on or off"
                                wire:click="toggleGateway('{{ $gateway['key'] }}', {{ $gateway['on'] ? 'false' : 'true' }})"
                                data-confirm-title="{{ 'Switch '.$gateway['label'].($gateway['on'] ? ' off?' : ' on?') }}"
                                data-confirm="{{ $gateway['on'] ? ($gateway['alone_on'] === [] ? 'New payments it would take go to the next gateway on each row. Payments already under way still finish.' : 'It is the only switched-on gateway for: '.implode(', ', $gateway['alone_on']).'. Those will grey out for customers until another gateway is on. Payments already under way still finish.') : 'It starts taking new payments on every row where it is first.' }}"
                                data-confirm-text="{{ $gateway['on'] ? 'Switch off' : 'Switch on' }}"
                                data-confirm-tone="{{ $gateway['on'] ? 'danger' : 'success' }}"
                                @disabled(! $gateway['implemented'])
                                class="relative inline-flex h-7 w-12 shrink-0 cursor-pointer rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50 {{ $switch($gateway['on']) }}"
                            >
                                <span class="pointer-events-none inline-block h-6 w-6 translate-y-0.5 transform rounded-full bg-white shadow-sm transition-transform {{ $knob($gateway['on']) }}"></span>
                            </button>
                            <span class="w-8 text-xs font-bold uppercase tracking-wider {{ $gateway['on'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $gateway['on'] ? 'On' : 'Off' }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ───────────────────────────── Routing ────────────────────────────── --}}
    @if ($tab === 'routing')
        <div class="flex flex-wrap items-center gap-1.5" data-tab="routing">
            @foreach ($this->currencies() as $code)
                <button type="button" wire:click="chooseCurrency('{{ $code }}')" class="{{ $pill($currency === $code) }}">{{ $code }}</button>
            @endforeach
        </div>

        <section class="{{ $card }} !p-0 overflow-hidden">
            <ul class="divide-y divide-zinc-100 dark:divide-white/5">
                @forelse ($this->routes as $route)
                    <li class="flex flex-col gap-3 px-5 py-4" wire:key="route-{{ $route['id'] }}">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="flex shrink-0 flex-col">
                                <button type="button" wire:click="moveMethod({{ $route['id'] }}, 'up')" @disabled($loop->first) class="text-zinc-400 hover:text-zinc-700 disabled:opacity-30" aria-label="Move {{ $route['label'] }} up">&#9650;</button>
                                <button type="button" wire:click="moveMethod({{ $route['id'] }}, 'down')" @disabled($loop->last) class="text-zinc-400 hover:text-zinc-700 disabled:opacity-30" aria-label="Move {{ $route['label'] }} down">&#9660;</button>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ $route['label'] }}</p>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if (! $route['offered'])
                                        Not offered in {{ $currency }}.
                                    @elseif (! $route['fiat'])
                                        Its own rail.
                                    @elseif ($route['taking'])
                                        Taking payments now: <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ \App\Domain\Shared\Enums\PaymentGateway::tryFrom($route['taking'])?->label() ?? $route['taking'] }}</span>
                                    @else
                                        <span class="font-semibold text-amber-700 dark:text-amber-300">No gateway on this row is switched on: the tile is greyed.</span>
                                    @endif
                                    @if ($route['updated'])
                                        <span class="text-zinc-400"> · last changed by {{ $route['updated'] }}</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $route['offered'] ? 'true' : 'false' }}"
                                    aria-label="Offer {{ $route['label'] }} in {{ $currency }}"
                                    wire:click="toggleOffered({{ $route['id'] }}, {{ $route['offered'] ? 'false' : 'true' }})"
                                    data-confirm-title="{{ ($route['offered'] ? 'Stop offering ' : 'Offer ').$route['label'].' in '.$currency.'?' }}"
                                    data-confirm="{{ $route['offered'] ? 'Customers paying in '.$currency.' will no longer see it. Payments already under way still finish.' : 'Customers paying in '.$currency.' will see it straight away.' }}"
                                    data-confirm-text="{{ $route['offered'] ? 'Stop offering' : 'Offer it' }}"
                                    data-confirm-tone="{{ $route['offered'] ? 'danger' : 'success' }}"
                                    class="relative inline-flex h-7 w-12 shrink-0 cursor-pointer rounded-full transition-colors {{ $switch($route['offered']) }}"
                                >
                                    <span class="pointer-events-none inline-block h-6 w-6 translate-y-0.5 transform rounded-full bg-white shadow-sm transition-transform {{ $knob($route['offered']) }}"></span>
                                </button>
                                <span class="w-16 text-xs font-bold uppercase tracking-wider {{ $route['offered'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $route['offered'] ? 'Offered' : 'Off' }}</span>
                            </div>
                        </div>

                        @if ($route['fiat'])
                            <ol class="flex flex-wrap items-center gap-2 pl-8">
                                @foreach ($route['gateways'] as $position => $gateway)
                                    <li @class([
                                        'flex items-center gap-1.5 rounded-[10px] px-2.5 py-1.5 text-xs ring-1',
                                        'bg-white ring-zinc-200 dark:bg-[#0c1a36] dark:ring-zinc-700/60' => $gateway['listed'],
                                        'bg-zinc-50 text-zinc-400 ring-dashed ring-zinc-200 dark:bg-white/5 dark:ring-zinc-700/60' => ! $gateway['listed'],
                                    ])>
                                        @if ($gateway['listed'])
                                            <span class="font-bold text-zinc-400">{{ $position + 1 }}</span>
                                        @endif
                                        <span class="font-semibold {{ $gateway['listed'] ? 'text-zinc-800 dark:text-zinc-100' : '' }}">{{ $gateway['label'] }}</span>
                                        @if (! $gateway['able'])
                                            <span class="text-[10px] text-zinc-400">cannot take {{ $currency }}</span>
                                        @elseif (! $gateway['on'])
                                            <span class="text-[10px] text-amber-600 dark:text-amber-400">switched off</span>
                                        @endif
                                        @if ($gateway['listed'])
                                            <button type="button" wire:click="moveGateway({{ $route['id'] }}, '{{ $gateway['key'] }}', 'up')" class="text-zinc-400 hover:text-zinc-700" aria-label="Move {{ $gateway['label'] }} earlier">&#8592;</button>
                                            <button type="button" wire:click="moveGateway({{ $route['id'] }}, '{{ $gateway['key'] }}', 'down')" class="text-zinc-400 hover:text-zinc-700" aria-label="Move {{ $gateway['label'] }} later">&#8594;</button>
                                        @endif
                                        <button type="button" wire:click="toggleGatewayOnRoute({{ $route['id'] }}, '{{ $gateway['key'] }}', {{ $gateway['listed'] ? 'false' : 'true' }})" @if ($gateway['listed'] && $gateway['successor'] === null && $route['offered']) disabled title="Keep at least one switched-on gateway that can take {{ $route['label'] }} in {{ $currency }}" @elseif ($gateway['listed']) data-confirm-title="Take {{ $gateway['label'] }} off {{ $route['label'] }} in {{ $currency }}?" data-confirm="{{ $gateway['successor'] !== null ? $gateway['successor'].' takes new '.$route['label'].' payments in '.$currency.' instead. Payments already under way still finish.' : 'This row is switched off, so nothing changes for customers until it is switched on again.' }}" data-confirm-text="Remove" data-confirm-tone="danger" @endif class="text-zinc-400 hover:text-zinc-700" aria-label="{{ $gateway['listed'] ? 'Remove' : 'Add' }} {{ $gateway['label'] }}">{{ $gateway['listed'] ? '×' : '+' }}</button>
                                    </li>
                                @endforeach
                            </ol>
                        @endif

                        @if ($route['method'] === 'mobile_money' && $route['offered'])
                            <p class="pl-8 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($route['coverage'] === [])
                                    No switched-on gateway takes mobile money in {{ $currency }} right now.
                                @else
                                    On offer now:
                                    @foreach ($route['coverage'] as $country => $networks)
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ \App\Domain\Payment\Support\GatewayCapabilities::countryName($country) }}</span>{{ $networks === [] ? '' : ' ('.implode(', ', $networks).')' }}@if (! $loop->last); @endif
                                    @endforeach
                                @endif
                            </p>
                        @endif

                        @if (! $route['offered'] || $route['note'])
                            <div class="pl-8" x-data="{ note: @js($route['note'] ?? '') }">
                                <label class="sr-only" for="note-{{ $route['id'] }}">Why it is off</label>
                                <input
                                    id="note-{{ $route['id'] }}"
                                    type="text"
                                    x-model="note"
                                    @keydown.enter.prevent="$wire.saveNote({{ $route['id'] }}, note)"
                                    @blur="$wire.saveNote({{ $route['id'] }}, note)"
                                    maxlength="255"
                                    placeholder="Why it is off, so nobody switches it back on blind"
                                    class="w-full rounded-[10px] border border-zinc-200 bg-white px-3 py-2 text-xs outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"
                                >
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-sm text-zinc-500">No payment methods are set up for this currency.</li>
                @endforelse
            </ul>
        </section>
    @endif

    {{-- ─────────────────────────── Eligibility ──────────────────────────── --}}
    @if ($tab === 'eligibility')
        @php $grid = $this->eligibility; @endphp
        <section class="{{ $card }}" data-tab="eligibility">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-bold text-zinc-900 dark:text-white">Enforce eligibility</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <button
                        type="button"
                        role="switch"
                        aria-checked="{{ $grid['enforced'] ? 'true' : 'false' }}"
                        aria-label="Enforce eligibility"
                        wire:click="toggleEnforcement({{ $grid['enforced'] ? 'false' : 'true' }})"
                        data-confirm-title="{{ $grid['enforced'] ? 'Stop enforcing eligibility?' : 'Enforce eligibility?' }}"
                        data-confirm="{{ $grid['enforced'] ? 'Every gateway may then take every category.' : 'Carts are refused on gateways not approved for them.' }}"
                        data-confirm-text="{{ $grid['enforced'] ? 'Stop enforcing' : 'Enforce' }}"
                        data-confirm-tone="{{ $grid['enforced'] ? 'danger' : 'success' }}"
                        class="relative inline-flex h-7 w-12 shrink-0 cursor-pointer rounded-full transition-colors {{ $switch($grid['enforced']) }}"
                    >
                        <span class="pointer-events-none inline-block h-6 w-6 translate-y-0.5 transform rounded-full bg-white shadow-sm transition-transform {{ $knob($grid['enforced']) }}"></span>
                    </button>
                    <span class="w-8 text-xs font-bold uppercase tracking-wider {{ $grid['enforced'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $grid['enforced'] ? 'On' : 'Off' }}</span>
                </div>
            </div>
        </section>

        <section class="{{ $card }} !p-0 overflow-hidden">
            <div class="overflow-x-auto p-3">
                <table class="w-full admin-table">
                    <thead>
                        <tr>
                            <th class="pl-5 text-left">Category</th>
                            @foreach ($grid['gateways'] as $gateway)
                                <th class="!text-center">{{ $gateway['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grid['rows'] as $row)
                            <tr>
                                <td class="pl-5 text-[13px] font-semibold text-zinc-900 dark:text-white">{{ $row['label'] }}</td>
                                @foreach ($grid['gateways'] as $gateway)
                                    @php $cell = $row['cells'][$gateway['key']]; @endphp
                                    <td class="text-center">
                                        @if ($cell === null)
                                            <span class="text-zinc-300">-</span>
                                        @else
                                            <button
                                                type="button"
                                                role="switch"
                                                aria-checked="{{ $cell ? 'true' : 'false' }}"
                                                aria-label="Allow {{ $row['label'] }} on {{ $gateway['label'] }}"
                                                wire:click="toggleCell('{{ $gateway['key'] }}', '{{ $row['category'] }}', {{ $cell ? 'false' : 'true' }})"
                                                class="relative inline-flex h-6 w-10 shrink-0 cursor-pointer rounded-full transition-colors {{ $switch($cell) }}"
                                            >
                                                <span class="pointer-events-none inline-block h-5 w-5 translate-y-0.5 transform rounded-full bg-white shadow-sm transition-transform {{ $cell ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                                            </button>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
