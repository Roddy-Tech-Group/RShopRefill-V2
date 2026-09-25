<?php

use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.dashboard')]
#[Title('Order history')]
class extends Component {
    /** How many more orders each scroll to the bottom brings in. */
    private const CHUNK = 10;

    /** Search by order id / order number. */
    public string $search = '';

    /** Show terminal (failed / cancelled) orders instead of active ones. */
    public bool $expired = false;

    /**
     * How many orders are on the page right now. The list has no page
     * numbers: reaching the bottom grows this figure and the next chunk
     * renders under the rows already there, so a customer with thousands
     * of orders scrolls one list rather than paging through it. Locked so
     * the browser cannot ask for the whole history in one request.
     */
    #[Locked]
    public int $visible = self::CHUNK;

    public function updatedSearch(): void
    {
        $this->visible = self::CHUNK;
    }

    public function updatedExpired(): void
    {
        $this->visible = self::CHUNK;
    }

    public function loadMore(): void
    {
        $this->visible += self::CHUNK;
    }

    public function with(): array
    {
        // orderByDesc('id') breaks created_at ties - two orders placed in the same
        // second would otherwise be free to swap between page loads, showing the
        // customer one order twice and hiding another.
        $query = Auth::user()->orders()->with(['items', 'paymentAttempts'])->latest()->orderByDesc('id');

        if (($term = trim($this->search)) !== '') {
            $query->where(fn ($q) => $q->where('id', 'like', "%{$term}%")
                ->orWhere('order_number', 'like', "%{$term}%"));
        }

        // No backend "expired" field exists — failed/cancelled orders are the
        // closest meaningful split, so the toggle flips between the two sets.
        if ($this->expired) {
            $query->whereIn('order_status', ['failed', 'cancelled']);
        } else {
            $query->whereNotIn('order_status', ['failed', 'cancelled']);
        }

        // A single page sized to what is on screen. The simple paginator
        // fetches one row past it, so hasMorePages() says whether another
        // chunk exists without counting the customer's whole history.
        return ['orders' => $query->simplePaginate($this->visible, ['*'], 'page', 1)];
    }
}; ?>

@php
    $countryNames = array_flip(config('countries.codes', [])); // ISO -> name

    // Inline status line per the order-row design: [label, textTone, icon].
    // "Delivered" (green check) is the customer word for a completed order.
    $statusLine = [
        'completed'           => ['Delivered',          'text-emerald-600 dark:text-emerald-400', 'check'],
        'partially_completed' => ['Partially complete', 'text-blue-600 dark:text-blue-300',       'check'],
        'processing'          => ['Processing',         'text-blue-600 dark:text-blue-300',       'clock'],
        // A pending order is an unpaid checkout, and the row must say so
        // rather than implying a purchase is in progress.
        'pending'             => ['Awaiting payment',   'text-amber-600 dark:text-amber-400',     'clock'],
        'failed'              => ['Failed',             'text-red-600 dark:text-red-400',         'x'],
        'cancelled'           => ['Cancelled',          'text-zinc-500 dark:text-zinc-400',       'x'],
        'requires_attention'  => ['Needs review',       'text-amber-600 dark:text-amber-400',     'clock'],
    ];

    // Generic payment label — the gateway/provider name is never shown.
    $methodLabels = ['wallet' => 'Wallet', 'crypto' => 'Crypto', 'flutterwave' => 'Card'];

    // Pull a copyable redemption code out of an order item's fulfillment payload.
    $extractCode = function ($item): ?string {
        $payload = (array) ($item->fulfillment_payload ?? []);
        foreach (['pin', 'code', 'redeem_code', 'voucher_code', 'card_number'] as $key) {
            if (! empty($payload[$key]) && is_scalar($payload[$key])) {
                return (string) $payload[$key];
            }
        }
        foreach ($payload as $key => $value) {
            // eSIM/topup payload fields are never redemption codes - without
            // this skip, an eSIM payload leaks its SM-DP+ address as "CODE".
            if (str_starts_with((string) $key, 'esim') || str_starts_with((string) $key, 'topup')
                || in_array($key, ['lpa', 'iccid', 'qrcode_url', 'qr_manual_code', 'network', 'provider_reference'], true)) {
                continue;
            }
            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    };
@endphp

<div class="flex w-full flex-col gap-5">

    {{-- Heading --}}
    <h1 class="hidden text-xl font-bold tracking-tight text-zinc-900 sm:text-3xl lg:block">Order history</h1>

    {{-- Search + expired filter --}}
    <div>
        <label for="order-search" class="block text-sm font-bold text-zinc-900">Enter your order id</label>
        <div class="mt-2">
            <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-zinc-900 dark:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    id="order-search"
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="019e34c4-9b70-726f-96cc-cf42b222d88a"
                    class="w-full rounded-full py-2 sm:rounded-[12px] sm:py-3 border-2 border-zinc-400 bg-transparent pl-12 pr-4 text-sm text-zinc-900 placeholder:text-zinc-500 outline-none transition-colors hover:border-zinc-500 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                >
                <div wire:loading wire:target="search" class="absolute right-3 top-1/2 -translate-y-1/2">
                    <svg class="h-4 w-4 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                        <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3z"/>
                    </svg>
                </div>
            </div>
            {{-- Expired filter sits under the search bar --}}
            <label class="mt-3 flex w-fit cursor-pointer items-center gap-2.5 rounded-[12px] px-1 py-1">
                <input type="checkbox" wire:model.live="expired" class="h-5 w-5 rounded-[12px] border-2 border-zinc-300 text-blue-600 focus:ring-2 focus:ring-blue-500/30">
                <span class="text-sm font-semibold text-zinc-700">Expired orders</span>
            </label>
        </div>
    </div>

    @if ($orders->isNotEmpty())
        {{-- All orders live in ONE card; each order is a drop-down row.
             Opening one auto-closes the previously-opened one
             (single-expanded accordion behaviour). The newest order starts
             open. The card's key follows the filter, not the loaded count:
             a new search swaps the card and opens its own first order, while
             loading more morphs it and leaves whatever is open alone. --}}
        <div wire:key="orders-{{ md5(trim($search).'|'.(int) $expired) }}" x-data="{ openId: @js((string) $orders->first()->id) }" class="divide-y divide-zinc-200 overflow-hidden rounded-[12px] bg-[#ffffff] shadow-md shadow-zinc-900/[0.06] dark:divide-zinc-700 dark:shadow-none">
            @foreach ($orders as $order)
                @php
                    [$lineLabel, $lineTone, $lineIcon] = $statusLine[$order->order_status->value] ?? ['Placed', 'text-amber-600 dark:text-amber-400', 'clock'];

                    // A cancelled order nobody paid for is an expired checkout,
                    // not a cancelled purchase; "Cancelled" reads like something
                    // was taken away.
                    $rowPayment = $order->payment_status->value ?? $order->payment_status;
                    if ($order->order_status->value === 'cancelled' && $rowPayment !== 'paid') {
                        [$lineLabel, $lineTone, $lineIcon] = ['Expired', 'text-zinc-500 dark:text-zinc-400', 'x'];
                    }
                    // Rcoin is earned on the USD settlement total, never the
                    // display-currency figure (a XAF 1,561 order is ~$2.75,
                    // which earns ~3 Rcoin - not 1,561).
                    $points = app(\App\Domain\Rewards\Services\RewardEngine::class)->cashbackPreviewFor($order);

                    // Row identity comes from the first item: brand art + name +
                    // face value ("Everything Apple $20"); extra variants fold
                    // into a "+N more" suffix.
                    $first    = $order->items->first();
                    $fsnap    = $first?->product_snapshot ?? [];
                    $fvsnap   = $first?->variant_snapshot ?? [];
                    $fbrand   = $fsnap['brand_key'] ?? null;
                    $fname    = $fbrand ? Product::brandDisplayName($fbrand) : ($fsnap['name'] ?? 'Order');
                    $flogo    = Product::brandLogoUrl($fbrand, $fsnap['logo_url'] ?? null);
                    $ffaceVal = $fvsnap['face_value'] ?? null;
                    $ffaceCur = $fvsnap['currency'] ?? ($fsnap['currency_code'] ?? 'USD');
                    $ffaceTxt = $ffaceVal !== null
                        ? Product::currencySymbol($ffaceCur).rtrim(rtrim(number_format((float) $ffaceVal, 2), '0'), '.')
                        : null;
                    $fcountry = $countryNames[strtoupper((string) ($fsnap['country_code'] ?? ''))] ?? ($fsnap['country_code'] ?? null);
                    $extraVariants = max(0, $order->items->groupBy('product_variant_id')->count() - 1);
                    $shortId  = str($order->id)->limit(18, '...').substr((string) $order->id, -6);
                @endphp
                <div wire:key="order-{{ $order->id }}">
                    {{-- Row header - brand art + title + meta + status; tap anywhere to expand. --}}
                    <button type="button" @click="openId = openId === @js((string) $order->id) ? null : @js((string) $order->id)" class="flex w-full items-center gap-3.5 px-4 py-3.5 text-left">
                        {{-- Mirrors the cart's gift-card tile (landscape art,
                             object-cover) at a smaller size. --}}
                        <span class="theme-static flex h-12 w-[4.5rem] shrink-0 items-center justify-center overflow-hidden rounded-[6px] bg-white shadow-sm ring-1 ring-zinc-200">
                            @if ($flogo)
                                <img src="{{ $flogo }}" alt="{{ $fname }}" class="h-full w-full object-cover" loading="lazy">
                            @else
                                <span class="text-sm font-black uppercase text-zinc-400">{{ str($fname)->substr(0, 2)->upper() }}</span>
                            @endif
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[10px] font-bold text-zinc-900 dark:text-white">
                                {{ $fname }}@if ($ffaceTxt) {{ $ffaceTxt }}@endif @if ($extraVariants > 0)<span class="font-medium text-zinc-500 dark:text-zinc-400">+{{ $extraVariants }} more</span>@endif
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ ($order->placed_at ?? $order->created_at)->format('M j') }}@if ($fcountry) <span class="px-0.5">·</span> {{ $fcountry }}@endif <span class="px-0.5">·</span> {{ $shortId }}
                            </span>
                            <span class="mt-1 flex items-center gap-1.5 text-xs font-semibold {{ $lineTone }}">
                                @if ($lineIcon === 'check')
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                @elseif ($lineIcon === 'x')
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                @else
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                @endif
                                {{ $lineLabel }}
                            </span>
                        </span>
                        <svg class="h-5 w-5 shrink-0 text-zinc-500 transition-transform duration-200" :class="openId === @js((string) $order->id) && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    {{-- Expanded detail --}}
                    <div x-show="openId === @js((string) $order->id)" style="display: none;" class="px-5 pb-5">

                        {{-- Meta rows --}}
                        <dl class="space-y-3 text-sm">
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-zinc-500">Order id</dt>
                                <dd class="flex items-center gap-2 text-right">
                                    <span class="break-all font-medium text-zinc-900">{{ $order->id }}</span>
                                    <button
                                        type="button"
                                        x-data="{ copied: false }"
                                        @click="navigator.clipboard.writeText(@js($order->id)); copied = true; setTimeout(() => copied = false, 1500)"
                                        class="shrink-0 text-zinc-400 transition-colors hover:text-blue-600"
                                        aria-label="Copy order id"
                                    >
                                        <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75"/>
                                        </svg>
                                        <svg x-show="copied" x-cloak class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                        </svg>
                                    </button>
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-zinc-500">Payment method</dt>
                                <dd class="font-semibold text-zinc-900">{{ $methodLabels[$order->payment_method] ?? 'Card' }}</dd>
                            </div>
                            {{-- The channel the money actually travelled. The order's own
                                 method folds card and mobile money together; the paid
                                 attempt tells them apart. Customer words only, never the
                                 provider's name. --}}
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-zinc-500">Payment gateway</dt>
                                <dd class="font-semibold text-zinc-900">{{ $order->customerGatewayLabel() }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-zinc-500">Earned points</dt>
                                <dd class="flex items-center gap-1.5 font-semibold text-zinc-900">
                                    <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-5 w-5 object-contain">
                                    {{ number_format($points) }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-zinc-500">Total amount</dt>
                                {{-- total_amount is stored in the customer's display currency
                                     (face_value + markup, converted via the currency rate at order time). --}}
                                <dd class="font-bold text-zinc-900">@moneyCode((float) $order->total_amount, $order->display_currency)</dd>
                            </div>
                        </dl>

                        <a
                            href="{{ route('shop.contact') }}?subject={{ rawurlencode('Help with order ' . $order->id) }}"
                            wire:navigate
                            class="mt-4 inline-flex items-center gap-2 text-sm text-zinc-600 underline underline-offset-2 transition-colors hover:text-blue-700"
                        >
                            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/>
                            </svg>
                            Need help with your order? Contact us!
                        </a>

                        {{-- Download the card art to the phone. fetch->blob forces a
                             real download even for cross-origin CDN logos; if the CDN
                             blocks CORS it falls back to opening the image (long-press
                             to save). Only shown when the order's brand has a logo. --}}
                        @php
                            $dlSnap = $order->items->first()?->product_snapshot ?? [];
                            $dlBrandKey = $dlSnap['brand_key'] ?? null;
                            $dlLogo = Product::brandLogoUrl($dlBrandKey, $dlSnap['logo_url'] ?? null);
                            $dlName = str($dlBrandKey ? Product::brandDisplayName($dlBrandKey) : ($dlSnap['name'] ?? 'gift-card'))->slug();
                        @endphp
                        @if ($dlLogo)
                            <button
                                type="button"
                                x-data
                                @click="(async () => {
                                    try {
                                        const res = await fetch(@js($dlLogo));
                                        const blob = await res.blob();
                                        const url = URL.createObjectURL(blob);
                                        const a = document.createElement('a');
                                        a.href = url;
                                        a.download = @js($dlName.'-card');
                                        document.body.appendChild(a);
                                        a.click();
                                        a.remove();
                                        URL.revokeObjectURL(url);
                                    } catch (e) {
                                        window.open(@js($dlLogo), '_blank');
                                    }
                                })()"
                                class="ml-4 mt-4 inline-flex items-center gap-1.5 rounded-full bg-[#f4f6f5] px-3 py-1.5 text-xs font-semibold text-zinc-700 transition-colors hover:text-zinc-900"
                            >
                                <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                                </svg>
                                Download card
                            </button>
                        @endif

                        {{-- Gift cards — each delivered item shows the card with its copyable code --}}
                        <div class="mt-5 space-y-4">
                            @foreach ($order->items as $item)
                                @php
                                    $snap     = $item->product_snapshot ?? [];
                                    $vsnap    = $item->variant_snapshot ?? [];
                                    $brandKey = $snap['brand_key'] ?? null;
                                    $name     = $brandKey ? Product::brandDisplayName($brandKey) : ($snap['name'] ?? 'Item');
                                    $logo     = Product::brandLogoUrl($brandKey, $snap['logo_url'] ?? null);
                                    $faceVal  = $vsnap['face_value'] ?? null;
                                    $faceCur  = $vsnap['currency'] ?? ($snap['currency_code'] ?? 'USD');
                                    $faceTxt  = $faceVal !== null
                                        ? Product::currencySymbol($faceCur).rtrim(rtrim(number_format((float) $faceVal, 2), '0'), '.')
                                        : null;
                                    $country  = $countryNames[strtoupper((string) ($snap['country_code'] ?? ''))] ?? ($snap['country_code'] ?? null);
                                    $payload  = (array) ($item->fulfillment_payload ?? []);
                                    // pin-redemptionURL cards (e.g. virtual prepaid Visa) deliver the PIN
                                    // under `epin`, not `pin` - fall back to it so it shows as the PIN
                                    // instead of leaking through the generic code-picker below.
                                    $cardPin  = (! empty($payload['pin']) && is_scalar($payload['pin'])) ? (string) $payload['pin'] : ((! empty($payload['epin']) && is_scalar($payload['epin'])) ? (string) $payload['epin'] : null);
                                    $cardCode = null;
                                    foreach (['code', 'voucher_code', 'redeem_code', 'card_number', 'serial'] as $credKey) {
                                        if (! empty($payload[$credKey]) && is_scalar($payload[$credKey])) { $cardCode = (string) $payload[$credKey]; break; }
                                    }
                                    // The registration/redemption link is the customer's only entry point
                                    // for pin-redemptionURL cards - surface it as a button below.
                                    $redemptionUrl = (! empty($payload['redemption_url']) && is_scalar($payload['redemption_url'])) ? (string) $payload['redemption_url'] : null;
                                    // eSIM delivery is shaped completely differently from a gift card:
                                    // a QR image (qrcode_url) plus the SM-DP+ address (lpa) and an
                                    // activation code (matching_id) for manual entry. The presence of
                                    // any one of those payload keys flips the render to the eSIM card.
                                    $esim = null;
                                    if (! empty($payload['qrcode_url']) || ! empty($payload['lpa']) || ! empty($payload['iccid'])) {
                                        $esimManual = (array) ($payload['esim'] ?? []);
                                        $esim = [
                                            'qr'      => is_scalar($payload['qrcode_url'] ?? null) ? (string) $payload['qrcode_url'] : null,
                                            'lpa'     => is_scalar($payload['lpa'] ?? null) ? (string) $payload['lpa'] : null,
                                            'code'    => is_scalar($esimManual['manualActivationCode'] ?? null) ? (string) $esimManual['manualActivationCode'] : null,
                                            'iccid'   => is_scalar($payload['iccid'] ?? null) ? (string) $payload['iccid'] : null,
                                            // iOS tap-to-install URL — opens the eSIM provisioning flow
                                            // directly on iPhone (iOS 17.4+) without a QR scan.
                                            'install' => is_scalar($payload['direct_install_url'] ?? null)
                                                ? (string) $payload['direct_install_url']
                                                : (is_scalar($esimManual['directInstallUrl'] ?? null) ? (string) $esimManual['directInstallUrl'] : null),
                                            // Branded eSIMs Cloud sharing link — self-service portal
                                            // where the customer monitors usage, re-installs, tops up.
                                            'manage'  => is_scalar($payload['sharing_link'] ?? null)
                                                ? (string) $payload['sharing_link']
                                                : (is_scalar($esimManual['sharingLink'] ?? null) ? (string) $esimManual['sharingLink'] : null),
                                            // Access code for re-claiming the eSIM on the hosted page.
                                            'access'  => is_scalar($payload['sharing_access_code'] ?? null)
                                                ? (string) $payload['sharing_access_code']
                                                : (is_scalar($esimManual['sharingAccessCode'] ?? null) ? (string) $esimManual['sharingAccessCode'] : null),
                                        ];
                                    }
                                    // Mobile top-up confirmation: no code/PIN — the airtime is
                                    // credited directly to the recipient's phone. We surface the
                                    // number + a "Credited" confirmation tile instead.
                                    $topup = null;
                                    $topupPayload = (array) ($payload['topup'] ?? []);
                                    $topupPhone   = is_scalar($payload['topup_recipient_phone'] ?? null)
                                        ? (string) $payload['topup_recipient_phone']
                                        : (is_scalar($topupPayload['recipient_phone'] ?? null) ? (string) $topupPayload['recipient_phone'] : null);
                                    // Fall back to the metadata the buyer typed on the product page —
                                    // works even when the provider hasn't echoed it yet.
                                    if (! $topupPhone) {
                                        $itemMeta = (array) ($item->metadata ?? []);
                                        if (is_scalar($itemMeta['recipient_phone'] ?? null)) { $topupPhone = (string) $itemMeta['recipient_phone']; }
                                    }
                                    $isTopupCategory = ((string) ($snap['category']['slug'] ?? $item->category?->slug ?? '')) === 'mobile-airtime';
                                    if (! $esim && ($isTopupCategory || $topupPhone)) {
                                        $topup = [
                                            'phone' => $topupPhone,
                                            'operator' => $topupPayload['operator'] ?? null,
                                        ];
                                    }
                                    if (! $esim && ! $topup && $cardCode === null && $cardPin === null) { $cardCode = $extractCode($item); }
                                    
                                    // Prevent duplicate rendering
                                    if ($cardPin !== null && $cardCode === $cardPin) {
                                        $cardPin = null;
                                    }
                                    $redeemHtml = $snap['redeem_instructions'] ?? null;
                                    $termsHtml  = $snap['terms_and_conditions'] ?? null;
                                    // Supplier-issued redemption steps captured at fulfillment
                                    // (plain text with newlines, e.g. the POSA portal flow for
                                    // Discord Nitro). Distinct from the product-level HTML above.
                                    $supplierSteps = (! empty($payload['instructions']) && is_scalar($payload['instructions'])) ? (string) $payload['instructions'] : null;
                                    // Apple gift cards: build the redeem deep-link so iPhone customers can
                                    // tap once and have Apple's eShop open the App Store redemption sheet
                                    // with the code pre-filled. Format documented at apps.apple.com/redeem.
                                    $appleRedeemUrl = null;
                                    if (! $esim && $cardCode && in_array(strtolower((string) $brandKey), ['apple', 'itunes', 'app-store', 'apple-itunes'], true)) {
                                        $appleRedeemUrl = 'https://apps.apple.com/redeem?ctx=gifts&code='.rawurlencode($cardCode);
                                    }

                                    // The card wears the brand's own colour (config override,
                                    // else the Zendit-synced brand_color); text flips to white
                                    // on dark brands. No colour = the neutral grey card.
                                    $brandBg = Product::brandColor($brandKey, $snap['brand_color'] ?? null);
                                    $brandDark = Product::colorIsDark($brandBg);
                                    $cardInk = $brandDark ? 'text-white' : 'text-zinc-900';
                                    $cardSub = $brandDark ? 'text-white/75' : 'text-zinc-600';
                                @endphp
                                {{-- Gift card — brand logo + denomination, full width inside the order card.
                                     `theme-static`: resellers screenshot this to deliver to their own
                                     customers, so it must look identical in light and dark mode. --}}
                                {{-- No border: the soft shadow is what gives light brand colours
                                     (e.g. Apple's near-white) their edge against the white panel.
                                     bg-zinc-100 (not the #f4f6f5 token) on purpose: theme-static
                                     re-pins zinc-100 to light in dark mode, the hex token would
                                     flip to the dark ground and break the screenshot. --}}
                                <div class="theme-static max-w-[340px] rounded-[12px] bg-zinc-100 px-3 py-1.5 shadow-md shadow-zinc-900/[0.06]" @if ($brandBg) style="background: {{ $brandBg }};" @endif>
                                    <div class="flex items-start justify-between gap-3 px-2 pt-2">
                                        <div class="flex min-w-0 flex-col items-start gap-2.5">
                                            @if ($logo)
                                                <img src="{{ $logo }}" alt="{{ $name }}" class="h-16 w-auto max-w-[130px] rounded-[5px] object-contain {{ $brandDark ? '' : 'mix-blend-multiply' }}" loading="lazy">
                                            @else
                                                <span class="text-3xl font-black uppercase {{ $brandDark ? 'text-white/70' : 'text-zinc-400' }}">{{ str($name)->substr(0, 2)->upper() }}</span>
                                            @endif
                                            @unless ($logo && Product::logoCarriesName($brandKey))
                                                <p class="truncate text-sm font-bold {{ $cardInk }}">For {{ $name }}</p>
                                            @endunless
                                        </div>
                                        <div class="shrink-0 text-right">
                                            @if ($faceTxt)
                                                <p class="text-xl font-extrabold leading-none {{ $cardInk }}">{{ $faceTxt }}</p>
                                            @endif
                                            @if ($country)
                                                <p class="mt-1 flex items-center justify-end gap-1.5 text-sm {{ $cardSub }}">
                                                    @if (Product::flagUrl($snap['country_code'] ?? null))
                                                        <img src="{{ Product::flagUrl($snap['country_code'] ?? null) }}" alt="" class="h-3 w-[18px] rounded-[1px] object-cover ring-1 ring-zinc-200">
                                                    @endif
                                                    {{ $country }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Card body space (short, keeps the code pill close to the header) --}}
                                    <div class="h-8"></div>

                                    @if ($esim)
                                        {{-- eSIM: QR for scan + SM-DP+ address and activation code for manual entry. --}}
                                        <div class="space-y-2">
                                            @if ($esim['qr'])
                                                <div class="flex flex-col items-center gap-2 rounded-[12px] bg-white p-4">
                                                    <img src="{{ $esim['qr'] }}" alt="eSIM activation QR code" class="h-44 w-44 object-contain" loading="lazy">
                                                    <p class="text-center text-xs font-medium text-zinc-600">Scan this QR from another device to install your eSIM.</p>
                                                </div>
                                            @endif
                                            @if ($esim['install'])
                                                {{-- One-tap install on iPhone (iOS 17.4+). The link opens Apple's
                                                     eSIM provisioning flow without needing the QR scan. --}}
                                                <a href="{{ $esim['install'] }}" class="flex items-center justify-center gap-2 rounded-[12px] bg-zinc-900 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-zinc-800">
                                                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                                                    Install on this iPhone
                                                </a>
                                            @endif
                                            @if ($esim['manage'])
                                                {{-- Branded eSIMs Cloud portal — customer monitors usage,
                                                     re-installs, and tops up on a RshopRefill-branded page. --}}
                                                <a href="{{ $esim['manage'] }}" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.348 14.651a3.75 3.75 0 010-5.303m5.304 0a3.75 3.75 0 010 5.303m-7.425 2.122a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M5.106 18.894c-3.808-3.808-3.808-9.98 0-13.788m13.788 0c3.808 3.808 3.808 9.98 0 13.788M12 12h.008v.008H12V12z"/>
                                                    </svg>
                                                    Manage your eSIM
                                                </a>
                                            @endif
                                            @if ($esim['iccid'] && $item->provider_name === 'airalo' && $item->fulfillment_status->value === 'fulfilled')
                                                {{-- Native top-up — refill the same ICCID from the dashboard. --}}
                                                <a href="{{ route('dashboard.esim.topup', $item) }}" wire:navigate class="flex items-center justify-center gap-2 rounded-[12px] bg-white px-4 py-3 text-sm font-semibold text-blue-600 ring-1 ring-blue-200 transition-colors hover:bg-blue-50 dark:bg-[#26416b] dark:text-blue-300 dark:ring-blue-400/40 dark:hover:bg-[#34507a]">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                    Top up this eSIM
                                                </a>
                                            @endif
                                            @foreach (array_filter(['SM-DP+' => $esim['lpa'], 'Code' => $esim['code'], 'ICCID' => $esim['iccid'], 'Access' => $esim['access']]) as $credLabel => $credValue)
                                                <div class="flex items-center gap-3 rounded-[12px] bg-white px-4 py-3" wire:key="esim-{{ $item->id }}-{{ $credLabel }}">
                                                    <span class="w-14 shrink-0 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $credLabel }}</span>
                                                    <span class="min-w-0 flex-1 truncate text-sm font-bold tracking-wider text-zinc-900">{{ $credValue }}</span>
                                                    <button
                                                        type="button"
                                                        x-data="{ copied: false }"
                                                        @click="navigator.clipboard.writeText(@js($credValue)); copied = true; setTimeout(() => copied = false, 1500)"
                                                        class="shrink-0 text-zinc-400 transition-colors hover:text-blue-600"
                                                        aria-label="Copy {{ $credLabel }}"
                                                    >
                                                        <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75"/>
                                                        </svg>
                                                        <span x-show="copied" x-cloak class="text-xs font-bold text-emerald-600">Copied</span>
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @elseif ($topup)
                                        {{-- Mobile top-up — no code to copy; airtime lands on the
                                             recipient phone. Tile confirms the number that was credited. --}}
                                        <div class="flex items-center gap-3 rounded-[12px] bg-white px-4 py-3">
                                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-emerald-50 text-emerald-600">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                </svg>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Credited to</p>
                                                @if ($topup['phone'])
                                                    <p class="truncate text-base font-bold tabular-nums text-zinc-900">{{ $topup['phone'] }}</p>
                                                @else
                                                    <p class="text-sm text-zinc-600">Your phone number on file</p>
                                                @endif
                                                @if (! empty($topup['operator']))
                                                    <p class="mt-0.5 truncate text-[11px] font-medium text-zinc-600">{{ $topup['operator'] }}</p>
                                                @endif
                                            </div>
                                            @if ($topup['phone'])
                                                <button
                                                    type="button"
                                                    x-data="{ copied: false }"
                                                    @click="navigator.clipboard.writeText(@js($topup['phone'])); copied = true; setTimeout(() => copied = false, 1500)"
                                                    class="shrink-0 text-zinc-400 transition-colors hover:text-blue-600"
                                                    aria-label="Copy phone number"
                                                >
                                                    <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75"/>
                                                    </svg>
                                                    <span x-show="copied" x-cloak class="text-xs font-bold text-emerald-600">Copied</span>
                                                </button>
                                            @endif
                                        </div>
                                    @elseif ($cardCode || $cardPin || $redemptionUrl)
                                        {{-- Gift card — code + PIN, each independently copyable. --}}
                                        <div class="space-y-2">
                                            @foreach (array_filter(['Code' => $cardCode, 'Pin' => $cardPin]) as $credLabel => $credValue)
                                                <div class="flex items-center gap-2 rounded-full bg-white px-3.5 py-2" wire:key="cred-{{ $item->id }}-{{ $credLabel }}">
                                                    <span class="w-8 shrink-0 text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ $credLabel }}</span>
                                                    <span class="min-w-0 flex-1 truncate text-xs font-bold tracking-wider text-zinc-900">{{ $credValue }}</span>
                                                    <button
                                                        type="button"
                                                        x-data="{ copied: false }"
                                                        @click="navigator.clipboard.writeText(@js($credValue)); copied = true; setTimeout(() => copied = false, 1500)"
                                                        class="shrink-0 text-zinc-400 transition-colors hover:text-blue-600"
                                                        aria-label="Copy {{ $credLabel }}"
                                                    >
                                                        <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75"/>
                                                        </svg>
                                                        <span x-show="copied" x-cloak class="text-xs font-bold text-emerald-600">Copied</span>
                                                    </button>
                                                </div>
                                            @endforeach

                                            @if ($redemptionUrl)
                                                {{-- pin-redemptionURL cards: the registration link is the
                                                     customer's entry point - without it they cannot redeem. --}}
                                                <a href="{{ $redemptionUrl }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                                                    Redeem your card
                                                </a>
                                            @endif

                                            @if ($appleRedeemUrl)
                                                {{-- Apple deep link: on iPhone/iPad the App Store opens with the
                                                     code pre-filled, ready to add to the user's Apple account. --}}
                                                <a href="{{ $appleRedeemUrl }}" class="flex items-center justify-center gap-2 rounded-[12px] bg-zinc-900 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-zinc-800">
                                                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                                                    Add to Apple account
                                                </a>
                                            @endif
                                        </div>
                                    @else
                                        <div class="flex items-center gap-2 rounded-[12px] bg-white px-4 py-3 text-xs font-medium text-zinc-500">
                                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Your code appears here once payment clears.
                                        </div>
                                    @endif
                                </div>

                                {{-- Redeem / Terms — toggles inline disclosure panels populated
                                     from product_snapshot (HTML, sanitized via brand sync). --}}
                                <div x-data="{ panel: null }" class="mt-2.5 max-w-[340px]">
                                    <div class="flex flex-wrap justify-center gap-x-8 gap-y-1 text-sm">
                                        @if ($redeemHtml || $supplierSteps)
                                            <button type="button"
                                                @click="panel = (panel === 'redeem') ? null : 'redeem'"
                                                :aria-expanded="(panel === 'redeem').toString()"
                                                :class="panel === 'redeem' ? 'text-blue-700' : 'text-blue-600 hover:text-blue-700'"
                                                class="font-medium transition-colors">Redeem instructions</button>
                                        @endif
                                        @if ($termsHtml)
                                            <button type="button"
                                                @click="panel = (panel === 'terms') ? null : 'terms'"
                                                :aria-expanded="(panel === 'terms').toString()"
                                                :class="panel === 'terms' ? 'text-zinc-900' : 'text-zinc-600 hover:text-zinc-900'"
                                                class="font-medium transition-colors">Terms and conditions</button>
                                        @endif
                                    </div>

                                    @if ($redeemHtml || $supplierSteps)
                                        <div x-show="panel === 'redeem'" x-collapse x-cloak class="mt-3 rounded-[12px] bg-white px-4 py-3 text-left text-xs leading-relaxed text-zinc-700 [&_a]:text-blue-600 [&_a]:underline [&>ol]:list-decimal [&>ol]:pl-5 [&>p]:mb-2 [&>ul]:list-disc [&>ul]:pl-5">
                                            @if ($supplierSteps)
                                                <p class="whitespace-pre-line {{ $redeemHtml ? 'mb-2' : '' }}">{{ $supplierSteps }}</p>
                                            @endif
                                            {!! $redeemHtml !!}
                                        </div>
                                    @endif
                                    @if ($termsHtml)
                                        <div x-show="panel === 'terms'" x-collapse x-cloak class="mt-3 rounded-[12px] bg-white px-4 py-3 text-left text-xs leading-relaxed text-zinc-700 [&_a]:text-blue-600 [&_a]:underline [&>ol]:list-decimal [&>ol]:pl-5 [&>p]:mb-2 [&>ul]:list-disc [&>ul]:pl-5">
                                            {!! $termsHtml !!}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        {{-- Empty state. Filtered (search/expired) variant stays minimal because
             the customer is mid-flow looking for a specific order. The "no
             orders ever" variant is much warmer and offers four storefront
             surfaces to start from so the page doesn't feel like a dead end. --}}
        @if (trim($search) !== '' || $expired)
            <div class="rounded-[12px] bg-[#ffffff] px-6 py-16 text-center shadow-md shadow-zinc-900/[0.06] dark:ring-1 dark:ring-zinc-700/60 dark:shadow-none">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-[12px] bg-[#f4f6f5] text-blue-600 dark:bg-blue-500/15 dark:text-blue-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </span>
                <p class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">
                    @if (trim($search) !== '')
                        No orders match that id
                    @else
                        No expired orders
                    @endif
                </p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Try a different search or filter.</p>
            </div>
        @else
            @php
                // Quick-link cards for the empty state. Each opens a storefront
                // surface; icon assets live in /public/assets and the route
                // names match the existing nav so renames stay in one place.
                $shopLinks = [
                    [
                        'label'   => 'Gift Cards',
                        'caption' => 'Pay anyone, anywhere',
                        'icon'    => 'gift cards.svg',
                        'href'    => route('dashboard.shop.gift-cards'),
                    ],
                    [
                        'label'   => 'eSIMs',
                        'caption' => 'Travel data in 190+ countries',
                        'icon'    => 'esim.svg',
                        'href'    => route('dashboard.shop.esims'),
                    ],
                    [
                        'label'   => 'Mobile Top-ups',
                        'caption' => 'Recharge any number worldwide',
                        'icon'    => 'topup1.svg',
                        'href'    => route('dashboard.shop.topups'),
                    ],
                    [
                        'label'   => 'Bill Payments',
                        'caption' => 'Settle utility bills instantly',
                        'icon'    => 'Bills 2.svg',
                        'href'    => route('dashboard.shop.bills'),
                    ],
                ];
            @endphp

            <div class="overflow-hidden rounded-[12px] bg-[#ffffff] px-6 py-12 text-center shadow-md shadow-zinc-900/[0.06] dark:ring-1 dark:ring-zinc-700/60 dark:shadow-none sm:px-10">
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#f4f6f5] text-blue-600 dark:text-blue-300 dark:ring-1 dark:ring-blue-500/20">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/>
                    </svg>
                </span>
                <h3 class="mt-5 text-xl font-bold text-zinc-900 dark:text-white sm:text-2xl">You have no orders yet</h3>
                <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                    When you make your first purchase, your orders, redemption codes, and receipts will all live here. Pick a category below to get started.
                </p>

                {{-- Quick-link grid. 2 cols mobile, 4 cols desktop so the
                     storefront surfaces stay one tap away from the dashboard. --}}
                <div class="mx-auto mt-7 grid max-w-3xl grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
                    @foreach ($shopLinks as $link)
                        <a
                            href="{{ $link['href'] }}"
                            wire:navigate
                            class="group flex flex-col items-center gap-3 rounded-[12px] bg-[#f4f6f5] px-3 py-4 text-center transition-shadow hover:shadow-lg hover:shadow-zinc-900/[0.10] dark:border-2 dark:border-zinc-700/60 dark:hover:border-zinc-500"
                        >
                            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-[#ffffff] transition-colors group-hover:bg-blue-100 dark:bg-blue-500/15 dark:ring-1 dark:ring-blue-500/20">
                                <img src="{{ asset('assets/' . rawurlencode($link['icon'])) }}" alt="" class="h-6 w-6 object-contain" loading="lazy">
                            </span>
                            <span class="leading-tight">
                                <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ $link['label'] }}</span>
                                <span class="mt-0.5 block text-[11px] text-zinc-500 dark:text-zinc-400">{{ $link['caption'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>

                {{-- A button in the shop tiles' own #f4f6f5 surface, radius and
                     hover lift, so it reads as part of that set rather than a
                     text link trailing under it. --}}
                <a
                    href="{{ route('dashboard.shop.gift-cards') }}"
                    wire:navigate
                    class="mt-7 inline-flex items-center rounded-[12px] bg-[#f4f6f5] px-5 py-3 text-sm font-semibold text-zinc-900 transition-shadow hover:shadow-lg hover:shadow-zinc-900/[0.10] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:border-2 dark:border-zinc-700/60 dark:text-white dark:hover:border-zinc-500"
                >
                    Explore everything
                </a>
            </div>
        @endif
    @endif

    @if ($orders->hasMorePages())
        {{-- Scroll sentinel. As it nears the viewport (a good screen early,
             via the margin) it asks for the next chunk. Its key carries the
             loaded count, so every response hands the browser a fresh
             element: if the new rows still leave it on screen it fires
             again at once instead of waiting for a scroll that never needs
             to happen. It leaves with the last order. --}}
        <div
            wire:key="orders-more-{{ $visible }}"
            wire:intersect.margin.400px="loadMore"
            class="flex items-center justify-center gap-2 py-4 text-xs font-semibold text-zinc-500 dark:text-zinc-400"
        >
            <svg class="h-4 w-4 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3z"/>
            </svg>
            Loading more orders
        </div>
    @endif
</div>
