@php
    use App\Domain\Payment\Support\CustomerFailureCopy;
    use App\Models\Product;

    /** @var \App\Models\Order $order */

    $status = $order->order_status;

    // Honest-lifecycle flags. A pending order with no money in flight is a
    // checkout awaiting payment, not a purchase, and must say so. Session
    // status distinguishes "never engaged a payment method" from "bank
    // transfer/crypto submitted, confirmation pending".
    $heroAttempt = $order->paymentAttempts->sortByDesc('created_at')->first();
    $heroSessionStatus = $heroAttempt?->paymentSession?->status->value ?? $heroAttempt?->paymentSession?->status;
    $paymentValue = $order->payment_status->value ?? $order->payment_status;
    $moneyInFlight = in_array($heroSessionStatus, ['awaiting_transfer', 'awaiting_confirmation', 'processing'], true);
    $awaitingPayment = $status->value === 'pending' && $paymentValue === 'unpaid' && ! $moneyInFlight;
    // Sweeper-cancelled, never paid: an expired checkout, not a cancelled order.
    $expiredCheckout = $status->value === 'cancelled' && $paymentValue !== 'paid';
    // A declined payment on an order nobody paid for. The cart survives until a
    // payment confirms, so checkout resumes the same purchase; this page used
    // to say "you can try checking out again" and offer no way to do it, which
    // is where the recovery email's retry link landed.
    $declinedUnpaid = $status->value === 'failed' && $paymentValue !== 'paid';
    // Why the last payment was refused, in this reader's language, when the
    // session recorded a reason. Read at render time so a customer who paid
    // in one language and reads the order in another gets the latter.
    $failureLine = $declinedUnpaid
        ? CustomerFailureCopy::forSessionPayload($heroAttempt?->paymentSession?->payment_payload)
        : null;

    // Status-driven hero copy + colour. The order is `pending` straight after
    // checkout while payment is verified, then moves through the fulfillment engine.
    $ui = match ($status->value) {
        'completed' => [
            'tone' => 'emerald', 'icon' => 'check',
            'title' => __('orders.hero_completed_title'),
            'line'  => __('orders.hero_completed_line'),
        ],
        'partially_completed' => [
            'tone' => 'amber', 'icon' => 'clock',
            'title' => __('orders.hero_partial_title'),
            'line'  => __('orders.hero_partial_line'),
        ],
        'processing' => [
            'tone' => 'blue', 'icon' => 'clock',
            'title' => __('orders.hero_processing_title'),
            'line'  => __('orders.hero_processing_line'),
        ],
        'failed' => [
            'tone' => 'red', 'icon' => 'cross',
            'title' => __('orders.hero_failed_title'),
            'line'  => __('orders.hero_failed_line'),
        ],
        'cancelled' => [
            'tone' => 'zinc', 'icon' => 'cross',
            'title' => __('orders.hero_cancelled_title'),
            'line'  => __('orders.hero_cancelled_line'),
        ],
        'requires_attention' => [
            'tone' => 'amber', 'icon' => 'clock',
            'title' => __('orders.hero_review_title'),
            'line'  => __('orders.hero_review_line'),
        ],
        default => [
            'tone' => 'amber', 'icon' => 'clock',
            'title' => __('orders.hero_placed_title'),
            'line'  => __('orders.hero_placed_line'),
        ],
    };

    // Overrides for the two states the match above cannot see: nothing has
    // been paid yet, or nothing ever was.
    if ($awaitingPayment) {
        $ui = [
            'tone' => 'amber', 'icon' => 'clock',
            'title' => __('orders.hero_unpaid_title'),
            'line'  => __('orders.hero_unpaid_line'),
        ];
    } elseif ($expiredCheckout) {
        $ui = [
            'tone' => 'zinc', 'icon' => 'cross',
            'title' => __('orders.hero_expired_title'),
            'line'  => __('orders.hero_expired_line'),
        ];
    }

    $tones = [
        'emerald' => ['bg' => 'bg-emerald-50', 'fg' => 'text-emerald-600', 'ring' => 'ring-emerald-100'],
        'blue'    => ['bg' => 'bg-blue-50',    'fg' => 'text-blue-600',    'ring' => 'ring-blue-100'],
        'amber'   => ['bg' => 'bg-amber-50',   'fg' => 'text-amber-600',   'ring' => 'ring-amber-100'],
        'red'     => ['bg' => 'bg-red-50',     'fg' => 'text-red-600',     'ring' => 'ring-red-100'],
        'zinc'    => ['bg' => 'bg-zinc-100',   'fg' => 'text-zinc-600',    'ring' => 'ring-zinc-200'],
    ];
    $tone = $tones[$ui['tone']];

    $statusBadge = match ($status->value) {
        'completed'           => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'processing'          => 'bg-blue-50 text-blue-700 ring-blue-200',
        'failed'              => 'bg-red-50 text-red-700 ring-red-200',
        'cancelled'           => 'bg-zinc-100 text-zinc-600 ring-zinc-200',
        default               => 'bg-amber-50 text-amber-700 ring-amber-200',
    };

    // The enum's label() is English-only, so the badge reads
    // orders.status_{value}; a state added later without a key keeps label().
    $statusKey = 'orders.status_'.$status->value;
    $statusLabel = \Illuminate\Support\Facades\Lang::has($statusKey) ? __($statusKey) : $status->label();

    // Customer-facing payment label only — the gateway/provider name is never shown.
    // The order stores wallet / flutterwave / crypto; card + mobile money both
    // settle through Flutterwave, so they surface as the generic "Card".
    $methodLabels  = ['wallet' => __('orders.method_wallet'), 'crypto' => __('orders.method_crypto'), 'flutterwave' => __('orders.method_card')];
    $paymentMethod = $methodLabels[$order->payment_method] ?? __('orders.method_card');
    $deliveryEmail = $order->metadata['delivery_email'] ?? auth()->user()?->email;

    // Per-order category mix — drives which "what happens next" copy + which
    // bottom notice we show (the gift-card region notice is irrelevant for
    // pure top-up or eSIM orders).
    $orderCategories = collect($order->items)
        ->map(fn ($i) => (string) ($i->product_snapshot['category']['slug'] ?? $i->category?->slug ?? ''))
        ->filter()
        ->unique();
    $orderHasGiftCards = $orderCategories->contains(fn ($s) => ! in_array($s, ['mobile-airtime', 'esims', 'bill-payments'], true));
    $orderHasTopups    = $orderCategories->contains('mobile-airtime');
    $orderHasEsims     = $orderCategories->contains('esims');

    // total_amount + line subtotals are stored in settlement_currency (USD per
    // CheckoutService); display_currency is a presentation hint that the backend's
    // FX pipeline doesn't yet convert against (exchange_rate_snapshot = 1.0 stub).
    // Render against settlement_currency so the symbol and number agree.
    $sym   = Product::currencySymbol($order->settlement_currency ?: 'USD');
    $money = fn ($v) => $sym . number_format((float) $v, 2);
    $rcoinEnabled = (bool) \App\Models\Setting::get('rcoin_enabled', true);
    // Settlement USD via the engine's preview - total_amount is the
    // display-currency figure (1249.60 XAF must not show as 1249 points).
    $points = app(\App\Domain\Rewards\Services\RewardEngine::class)->cashbackPreviewFor($order);
    $isPending = in_array($status->value, ['pending', 'processing'], true);

    $latestAttempt = $order->paymentAttempts->sortByDesc('created_at')->first();
    $paymentSession = $latestAttempt?->paymentSession;
    $sessionActive = $paymentSession && in_array($paymentSession->status->value ?? $paymentSession->status, ['pending', 'awaiting_payment']);

    // Keep "continue shopping" inside the dashboard chrome when the order page is
    // reached under /dashboard/shop/* (x-shop.layout renders dashboard chrome there).
    $inDashboard = request()->is('dashboard/shop*');
    $shopRoute = fn (string $name, $params = []) => route(($inDashboard ? 'dashboard.shop.' : 'shop.').$name, $params);
@endphp

<x-shop.layout :title="__('orders.order') . ' ' . $order->order_number . ' | '.$siteName">

@if ($status->value === 'completed')
    {{-- ── Clean success view for completed orders ──────────────────────
         Mobile-first, focused on the deliverable (codes + actions). Renders
         the original detailed view only for non-completed states below. --}}
    @php
        $tpProfileUrl = config('services.trustpilot.profile_url');
        // The review collector widget's public ids, and the platform name as
        // the reviews page labels it (a setting, never a literal).
        $tp = config('services.trustpilot');
        $reviewPlatform = (string) \App\Models\SiteSetting::get('reviews.aggregate.source', 'Trustpilot');
        $groupedItemsSuccess = $order->items->groupBy('product_variant_id');

        // Supplier-issued redemption steps captured at fulfillment (plain text
        // with newlines, e.g. the POSA portal flow for Discord Nitro). Grouped
        // by brand so a mixed order labels each block; deduped because every
        // unit of the same variant carries identical instructions.
        $supplierRedeemSteps = $order->items
            ->map(function ($orderItem) {
                $itemPayload = (array) ($orderItem->fulfillment_payload ?? []);
                $steps = $itemPayload['instructions'] ?? null;
                if (empty($steps) || ! is_scalar($steps)) {
                    return null;
                }
                $stepBrandKey = $orderItem->product_snapshot['brand_key'] ?? null;

                return [
                    'brand' => $stepBrandKey ? Product::brandDisplayName($stepBrandKey) : ($orderItem->product_snapshot['name'] ?? __('orders.card_fallback_name')),
                    'steps' => (string) $steps,
                ];
            })
            ->filter()
            ->unique(fn ($row) => $row['brand'].'|'.$row['steps'])
            ->values();
    @endphp

    {{-- TrustBox bootstrap. Loaded once; the collector widget below renders into it. --}}
    @if ($tpProfileUrl)
        <script type="text/javascript" src="//widget.trustpilot.com/bootstrap/v5/tp.widget.bootstrap.min.js" async></script>
    @endif

    <div class="min-h-full bg-zinc-50 dark:bg-[#0c1a36]">
        <div class="mx-auto w-full max-w-sm px-4 py-8 sm:py-10">

            {{-- Hero with confetti splash: a bouncy green tick + particle burst.
                 Shared component so the order page and the payment-success modals
                 all celebrate identically. --}}
            <div class="text-center">
                <x-success-tick />
                <h1 class="mt-5 text-2xl font-bold text-zinc-900 dark:text-white">{{ __('orders.success_heading') }}</h1>
                <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">{{ __('orders.success_thanks') }}</p>
            </div>

            {{-- Download as CSV --}}
            <div class="mt-7 flex justify-center">
                <a href="{{ route('shop.order.codes.csv', $order->order_number) }}" class="inline-flex items-center gap-2 text-sm font-semibold text-zinc-900 underline underline-offset-4 transition-colors hover:text-blue-700 dark:text-white dark:hover:text-blue-300">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    {{ __('orders.download_csv') }}
                </a>
            </div>

            {{-- Per-unit product cards - exact copy of the dashboard orders
                 page card design (livewire/dashboard/orders.blade.php) so the
                 same gift-card look shows on both surfaces. --}}
            @php
                $countryNamesSuccess = array_flip(config('countries.codes', []));
                // Visible credential labels. The English array keys below stay
                // as they are because they also build each pill's wire:key.
                $credLabels = ['Code' => __('orders.credential_code'), 'Pin' => __('orders.credential_pin')];
            @endphp
            <div class="mt-6 space-y-4">
                @foreach ($groupedItemsSuccess as $variantItems)
                    @foreach ($variantItems as $idx => $item)
                        @php
                            $snap = $item->product_snapshot ?? [];
                            $vsnap = $item->variant_snapshot ?? [];
                            $brandKey = $snap['brand_key'] ?? null;
                            $name = $brandKey ? \App\Models\Product::brandDisplayName($brandKey) : ($snap['name'] ?? __('orders.item_fallback_name'));
                            $logo = \App\Models\Product::brandLogoUrl($brandKey, $snap['logo_url'] ?? null);
                            $faceVal = $vsnap['face_value'] ?? null;
                            $faceCur = $vsnap['currency'] ?? ($snap['currency_code'] ?? 'USD');
                            $faceTxt = $faceVal !== null
                                ? \App\Models\Product::currencySymbol($faceCur).rtrim(rtrim(number_format((float) $faceVal, 2), '0'), '.')
                                : ($sym . number_format((float) $item->display_amount, 0));
                            $country = $countryNamesSuccess[strtoupper((string) ($snap['country_code'] ?? ''))] ?? ($snap['country_code'] ?? null);
                            $payload = (array) ($item->fulfillment_payload ?? []);
                            $cardPin = (! empty($payload['pin']) && is_scalar($payload['pin'])) ? (string) $payload['pin'] : null;
                            $cardCode = null;
                            foreach (['code', 'voucher_code', 'redeem_code', 'card_number', 'serial', 'activation_code'] as $credKey) {
                                if (! empty($payload[$credKey]) && is_scalar($payload[$credKey])) { $cardCode = (string) $payload[$credKey]; break; }
                            }
                            $qrUrl = is_scalar($payload['qrcode_url'] ?? null) ? (string) $payload['qrcode_url'] : null;

                            // Top-up recipient number, resolved like the dashboard
                            // orders card: provider payload first, then the number
                            // the buyer typed at purchase (item metadata) - so the
                            // tile shows the phone even before fulfillment instead
                            // of the "code appears here" placeholder.
                            $topupPayload = (array) ($payload['topup'] ?? []);
                            $phone = is_scalar($payload['topup_recipient_phone'] ?? null)
                                ? (string) $payload['topup_recipient_phone']
                                : (is_scalar($topupPayload['recipient_phone'] ?? null) ? (string) $topupPayload['recipient_phone'] : null);
                            if (! $phone && is_scalar($payload['phone_number'] ?? null)) {
                                $phone = (string) $payload['phone_number'];
                            }
                            if (! $phone && is_scalar(((array) ($item->metadata ?? []))['recipient_phone'] ?? null)) {
                                $phone = (string) $item->metadata['recipient_phone'];
                            }
                            $phoneCredited = $item->fulfillment_status?->value === 'fulfilled';

                            // The card wears the brand's own colour (config override,
                            // else the Zendit-synced brand_color); text flips to white
                            // on dark brands. No colour = the neutral grey card.
                            $brandBg = \App\Models\Product::brandColor($brandKey, $snap['brand_color'] ?? null);
                            $brandDark = \App\Models\Product::colorIsDark($brandBg);
                            $cardInk = $brandDark ? 'text-white' : 'text-zinc-900';
                            $cardSub = $brandDark ? 'text-white/75' : 'text-zinc-600';
                        @endphp

                        {{-- Gift card - mirrors the dashboard orders page card so the
                             customer sees the same gift-card visual on both surfaces. --}}
                        {{-- Light brand colours (e.g. Apple's near-white) keep the zinc border so
     the card edge still reads against the white panel; dark brands merge
     border into the fill. --}}
<div class="theme-static mx-auto max-w-[340px] rounded-[12px] border-2 border-zinc-100 bg-zinc-100 px-3 py-1.5" @if ($brandBg) style="background: {{ $brandBg }};{{ $brandDark ? ' border-color: '.$brandBg.';' : '' }}" @endif>
                            <div class="flex items-start justify-between gap-3 px-2 pt-2">
                                <div class="flex min-w-0 flex-col items-start gap-2.5">
                                    @if ($logo)
                                        <img src="{{ $logo }}" alt="{{ $name }}" class="h-16 w-auto max-w-[130px] rounded-[5px] object-contain {{ $brandDark ? '' : 'mix-blend-multiply' }}" loading="lazy">
                                    @else
                                        <span class="text-3xl font-black uppercase {{ $brandDark ? 'text-white/70' : 'text-zinc-400' }}">{{ str($name)->substr(0, 2)->upper() }}</span>
                                    @endif
                                    @unless ($logo && \App\Models\Product::logoCarriesName($brandKey))
                                        <p class="truncate text-sm font-bold {{ $cardInk }}">{{ __('orders.card_for_brand', ['brand' => $name]) }}</p>
                                    @endunless
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-xl font-extrabold leading-none {{ $cardInk }}">{{ $faceTxt }}</p>
                                    @if ($country)
                                        <p class="mt-1 flex items-center justify-end gap-1.5 text-sm {{ $cardSub }}">
                                            @if (\App\Models\Product::flagUrl($snap['country_code'] ?? null))
                                                <img src="{{ \App\Models\Product::flagUrl($snap['country_code'] ?? null) }}" alt="" class="h-3 w-[18px] rounded-[1px] object-cover ring-1 ring-zinc-200">
                                            @endif
                                            {{ $country }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            {{-- Card body space (short, keeps the code pill close to the header) --}}
                            <div class="h-8"></div>

                            @if ($qrUrl)
                                <div class="flex flex-col items-center gap-2 rounded-[12px] border-2 border-zinc-100 bg-white p-4">
                                    <img src="{{ $qrUrl }}" alt="{{ __('orders.esim_qr_alt') }}" class="h-44 w-44 object-contain" loading="lazy">
                                    <p class="text-center text-xs font-medium text-zinc-600">{{ __('orders.esim_scan_hint') }}</p>
                                </div>
                            @elseif ($phone)
                                <div class="flex items-center gap-3 rounded-[12px] border-2 border-zinc-100 bg-white px-4 py-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                        </svg>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $phoneCredited ? __('orders.topup_credited_to') : __('orders.topup_will_be_credited_to') }}</p>
                                        <p class="truncate text-base font-bold tabular-nums text-zinc-900">{{ $phone }}</p>
                                    </div>
                                </div>
                            @elseif ($cardCode || $cardPin)
                                <div class="space-y-2">
                                    @foreach (array_filter(['Code' => $cardCode, 'Pin' => $cardPin]) as $credLabel => $credValue)
                                        <div class="flex items-center gap-2 rounded-full border border-zinc-100 bg-white px-3.5 py-2" wire:key="success-cred-{{ $item->id }}-{{ $credLabel }}">
                                            <span class="w-8 shrink-0 text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ $credLabels[$credLabel] }}</span>
                                            <span class="min-w-0 flex-1 truncate text-xs font-bold tracking-wider text-zinc-900">{{ $credValue }}</span>
                                            <button
                                                type="button"
                                                x-data="{ copied: false }"
                                                @click="navigator.clipboard.writeText(@js($credValue)); copied = true; setTimeout(() => copied = false, 1500)"
                                                class="shrink-0 text-zinc-400 transition-colors hover:text-blue-600"
                                                aria-label="{{ __('orders.copy_credential', ['label' => $credLabels[$credLabel]]) }}"
                                            >
                                                <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75"/>
                                                </svg>
                                                <span x-show="copied" x-cloak class="text-xs font-bold text-emerald-600">{{ __('common.copied') }}</span>
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="flex items-center gap-2 rounded-[12px] border-2 border-zinc-100 bg-white px-4 py-3 text-xs font-medium text-zinc-500">
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    {{ __('orders.code_pending') }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>

            {{-- ── Order-level actions: side-by-side help links that toggle an
                 inline collapsible panel beneath them. Single Alpine root tracks
                 which panel is open at a time. --}}
            <div class="mt-6" x-data="{
                    open: null,
                    toggle(name) { this.open = this.open === name ? null : name; },
                }">

                {{-- Side-by-side text links --}}
                <div class="mt-3 flex items-center justify-center gap-8 text-xs">
                    <button type="button" @click="toggle('redeem')" :class="open === 'redeem' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-500 dark:text-zinc-400'" class="transition-colors hover:text-blue-700 dark:hover:text-blue-300">
                        {{ __('orders.redeem_instructions') }}
                    </button>
                    <button type="button" @click="toggle('terms')" :class="open === 'terms' ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-500 dark:text-zinc-400'" class="transition-colors hover:text-blue-700 dark:hover:text-blue-300">
                        {{ __('orders.terms_and_conditions') }}
                    </button>
                </div>

                {{-- Inline collapsible panels - one renders at a time below the links. --}}
                <div x-show="open === 'redeem'" x-collapse x-cloak class="mt-3 overflow-hidden rounded-[12px] bg-white px-4 py-3 text-xs leading-relaxed text-zinc-600 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60">
                    {{-- Supplier steps come first: when present they are the
                         authoritative flow for that exact voucher. --}}
                    @foreach ($supplierRedeemSteps as $supplierStep)
                        <p class="{{ $loop->first ? '' : 'mt-3 ' }}font-semibold text-zinc-900 dark:text-white">{{ $supplierStep['brand'] }}</p>
                        <p class="mt-1 whitespace-pre-line">{{ $supplierStep['steps'] }}</p>
                    @endforeach
                    @if ($orderHasGiftCards)
                        <p class="{{ $supplierRedeemSteps->isNotEmpty() ? 'mt-3 ' : '' }}font-semibold text-zinc-900 dark:text-white">{{ __('orders.redeem_gift_cards_heading') }}</p>
                        <ol class="ml-4 mt-1 list-decimal space-y-1">
                            <li>{{ __('orders.redeem_gift_cards_step_1') }}</li>
                            <li>{{ __('orders.redeem_gift_cards_step_2') }}</li>
                            <li>{{ __('orders.redeem_gift_cards_step_3') }}</li>
                            <li>{{ __('orders.redeem_gift_cards_step_4') }}</li>
                        </ol>
                    @endif
                    @if ($orderHasEsims)
                        <p class="{{ $orderHasGiftCards ? 'mt-3' : '' }} font-semibold text-zinc-900 dark:text-white">{{ __('orders.redeem_esims_heading') }}</p>
                        <ol class="ml-4 mt-1 list-decimal space-y-1">
                            <li>{{ __('orders.redeem_esims_step_1') }}</li>
                            <li>{{ __('orders.redeem_esims_step_2') }}</li>
                            <li>{{ __('orders.redeem_esims_step_3') }}</li>
                            <li>{{ __('orders.redeem_esims_step_4') }}</li>
                        </ol>
                    @endif
                    @if ($orderHasTopups)
                        <p class="{{ $orderHasGiftCards || $orderHasEsims ? 'mt-3' : '' }} font-semibold text-zinc-900 dark:text-white">{{ __('orders.redeem_topups_heading') }}</p>
                        <p class="mt-1">{{ __('orders.redeem_topups_body') }}</p>
                    @endif
                </div>

                <div x-show="open === 'terms'" x-collapse x-cloak class="mt-3 overflow-hidden rounded-[12px] bg-white px-4 py-3 text-xs leading-relaxed text-zinc-600 ring-1 ring-zinc-100 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60">
                    <ul class="ml-4 list-disc space-y-1">
                        <li>{{ __('orders.terms_codes_cash', ['site' => $siteName]) }}</li>
                        @if ($orderHasEsims)
                            <li>{{ __('orders.terms_esims') }}</li>
                        @endif
                        @if ($orderHasTopups)
                            <li>{{ __('orders.terms_topups') }}</li>
                        @endif
                        <li>{!! __('orders.terms_full_legal', [
                            'link' => '<a href="'.route('shop.terms').'" wire:navigate class="text-blue-700 underline-offset-2 hover:underline dark:text-blue-300">'.e(__('orders.terms_full_legal_link')).'</a>',
                        ]) !!}</li>
                    </ul>
                </div>
            </div>

            {{-- Review collector for the platform that hosts our public reviews,
                 right under the delivered card while the purchase is fresh. The
                 TrustBox is the only branding of theirs on the page, as their
                 guidelines ask; the native form beneath collects for our own wall. --}}
            @if ($tpProfileUrl)
                <div class="mt-10 rounded-[12px] bg-white p-5 text-center shadow-sm shadow-zinc-900/5 dark:bg-[#1d3252] dark:shadow-none sm:p-6" data-trustbox>
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-[12px] bg-emerald-100 dark:bg-emerald-500/15">
                        <svg class="h-6 w-6 text-emerald-700 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                        </svg>
                    </div>
                    <h2 class="mt-4 text-lg font-bold text-zinc-900 dark:text-white">{{ __('reviews.review_us') }}</h2>
                    <p class="mx-auto mt-1 max-w-xs text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">{{ __('reviews.collector_body') }}</p>
                    <div class="mt-5 flex justify-center">
                        <div class="inline-flex overflow-hidden rounded-[12px] bg-white shadow-md shadow-zinc-900/20">
                            <div class="trustpilot-widget"
                                data-locale="{{ $tp['locale'] }}"
                                data-template-id="{{ $tp['review_collector_template_id'] }}"
                                data-businessunit-id="{{ $tp['business_unit_id'] }}"
                                data-style-height="52px"
                                data-style-width="240px"
                                data-token="{{ $tp['review_collector_token'] }}">
                                <a href="{{ $tpProfileUrl }}" target="_blank" rel="noopener" class="text-sm font-semibold text-blue-700 hover:underline">{{ __('reviews.review_fallback') }}</a>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Native review prompt, tied to this order so the rating rolls up
                 under every gift card bought in it. Shared with the public
                 reviews page (which renders it without an order). --}}
            <div class="mt-10">
                @include('shop._review-form', ['reviewOrderNumber' => $order->order_number])
            </div>

            {{-- Back to dashboard --}}
            <div class="mt-10 text-center">
                <a href="{{ route('dashboard.orders') }}" wire:navigate class="inline-flex items-center rounded-full bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-md transition-colors hover:bg-blue-700">{{ __('orders.view_all_orders') }}</a>
            </div>

            {{-- Reviews for the brand(s) just bought - the same per-brand
                 carousel as the product page. Self-guarding per brand, so
                 orders whose brands have no reviews add nothing here. --}}
            @php
                $reviewBrandKeys = $order->items
                    ->map(fn ($orderItem) => $orderItem->product_snapshot['brand_key'] ?? null)
                    ->filter()
                    ->unique()
                    ->values();
            @endphp
            @foreach ($reviewBrandKeys as $reviewBrandKey)
                <div class="mt-12">
                    <x-shop.brand-reviews :brand-key="$reviewBrandKey" :brand-name="\App\Models\Product::brandDisplayName($reviewBrandKey)" />
                </div>
            @endforeach

        </div>
    </div>

@else
    {{-- ── Existing detailed view for non-completed states (pending,
         processing, failed, etc.) - kept verbatim. ──────────────── --}}

<div class="min-h-full bg-zinc-100">
<div class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6 lg:py-10">

    {{-- Status hero --}}
    <section class="rounded-[12px] bg-white p-6 text-center shadow-sm shadow-zinc-900/5 sm:p-8">
        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-[12px] {{ $tone['bg'] }} ring-8 {{ $tone['ring'] }}">
            <svg class="h-8 w-8 {{ $tone['fg'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
                @if ($ui['icon'] === 'check')
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                @elseif ($ui['icon'] === 'cross')
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                @else
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                @endif
            </svg>
        </span>

        <h1 class="mt-5 text-2xl font-bold text-zinc-900">{{ $ui['title'] }}</h1>
        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-zinc-600">{{ $ui['line'] }}</p>
        @if ($failureLine)
            <p class="mx-auto mt-2 max-w-md text-sm font-semibold leading-relaxed text-red-600" data-failure-line>{{ $failureLine }}</p>
        @endif

        {{-- The unpaid checkout's way back in. The cart survives until payment
             confirms and checkout re-entry is idempotent, so this lands the
             customer on the same live session rather than a duplicate order. --}}
        @if ($awaitingPayment || $expiredCheckout || $declinedUnpaid)
            <a href="{{ $shopRoute('checkout') }}"
                class="mt-5 inline-flex items-center justify-center gap-2 rounded-full bg-blue-600 px-6 py-3 text-sm font-bold text-white shadow-md transition-colors hover:bg-blue-700">
                {{ $awaitingPayment ? __('orders.complete_payment') : __('common.try_again') }}
            </a>
        @endif

        <div class="mt-4 inline-flex items-center gap-2 rounded-[12px] bg-zinc-50 px-4 py-1.5 ring-1 ring-zinc-200">
            <span class="text-xs font-medium text-zinc-500">{{ __('orders.order') }}</span>
            <span class="text-sm font-bold tabular-nums text-zinc-900">{{ $order->order_number }}</span>
        </div>

        @if ($deliveryEmail)
            <p class="mt-3 text-sm text-zinc-600">
                {!! __('orders.confirmation_sent_to', [
                    'email' => '<span class="font-semibold text-zinc-900">'.e($deliveryEmail).'</span>',
                ]) !!}
            </p>
        @endif
    </section>

    {{-- Embedded Payment Orchestration Component --}}
    @if ($sessionActive)
        <section
            x-data="{
                session: @js(new \App\Http\Resources\PaymentSessionResource($paymentSession)),
                selectedMethod: null,
                cardDetails: {
                    card_number: '',
                    cvv: '',
                    expiry_month: '',
                    expiry_year: '',
                    card_holder: ''
                },
                pinValue: '',
                otpValue: '',
                momoDetails: {
                    phone_number: '',
                    network: ''
                },
                cryptoDetails: {
                    pay_currency: ''
                },
                paymentState: 'select_method', // select_method, card_input, action_pin, action_otp, action_3ds, awaiting_transfer, awaiting_confirmation, momo_input, processing, success, error
                errorMessage: '',
                actionMessage: '',
                // Why a payment failed, by the session's reason code, in this
                // customer's language (see the checkout wizard).
                failureCopy: @js(CustomerFailureCopy::map()),
                failureCode: null,
                bankDetails: null,
                pollInterval: null,

                init() {
                    this.resetCardDetails();
                    if (this.session.status === 'awaiting_transfer') {
                        this.paymentState = 'awaiting_transfer';
                        this.bankDetails = this.session.payment_payload?.bank_details || this.session.payment_payload || null;
                        this.startStatusPolling();
                    } else if (this.session.status === 'awaiting_confirmation') {
                        this.paymentState = 'awaiting_confirmation';
                        this.actionMessage = this.session.payment_payload?.message || @js(__('orders.pay_momo_authorize'));
                        this.startStatusPolling();
                    } else if (this.session.status === 'awaiting_customer_action') {
                        const action = this.session.payment_payload?.action;
                        if (action === 'pin') {
                            this.paymentState = 'action_pin';
                        } else if (action === 'otp') {
                            this.paymentState = 'action_otp';
                            this.actionMessage = this.session.payment_payload?.message || @js(__('orders.pay_otp_prompt'));
                        } else if (action === 'redirect') {
                            this.paymentState = 'action_3ds';
                            this.startStatusPolling();
                        }
                    }
                },

                resetCardDetails() {
                    this.cardDetails = {
                        card_number: '',
                        cvv: '',
                        expiry_month: '',
                        expiry_year: '',
                        card_holder: ''
                    };
                    this.momoDetails = {
                        phone_number: '',
                        network: ''
                    };
                },

                formatCardNumber(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    let formatted = '';
                    for (let i = 0; i < value.length; i++) {
                        if (i > 0 && i % 4 === 0) {
                            formatted += ' ';
                        }
                        formatted += value[i];
                    }
                    this.cardDetails.card_number = formatted;
                },

                selectPaymentMethod(method) {
                    this.selectedMethod = method;
                    if (method.type === 'card') {
                        this.paymentState = 'card_input';
                    } else if (method.type === 'bank_transfer') {
                        this.paySession('bank_transfer');
                    } else if (method.type === 'mobile_money') {
                        this.momoDetails.network = (method.supported_networks ?? [])[0] ?? '';
                        this.paymentState = 'momo_input';
                    } else if (method.type === 'crypto') {
                        this.cryptoDetails.pay_currency = method.coin || 'usdt';
                        this.paySession('crypto', { pay_currency: this.cryptoDetails.pay_currency });
                    } else if (method.type === 'apple_pay') {
                        this.paymentState = 'processing';
                        setTimeout(() => {
                            this.paymentState = 'success';
                            setTimeout(() => { window.location.reload(); }, 1500);
                        }, 2000);
                    }
                },

                async paySession(method, dataPayload = {}) {
                    this.paymentState = 'processing';
                    this.errorMessage = '';
                    this.failureCode = null;
                    try {
                        let body = {
                            method: method,
                            details: dataPayload
                        };
                        if (this.pinValue) {
                            body.pin = this.pinValue;
                        }
                        if (this.otpValue) {
                            body.otp = this.otpValue;
                            body.flw_ref = this.session.payment_payload?.flw_ref || '';
                        }

                        let response = await fetch(`/api/payment-sessions/${this.session.id}/pay`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                            },
                            body: JSON.stringify(body)
                        });

                        let resData;
                        try {
                            resData = await response.json();
                        } catch (jsonErr) {
                            resData = { message: @js(__('orders.pay_error_server')).replace(':status', response.status).replace(':text', response.statusText) };
                        }

                        if (!response.ok) {
                            this.paymentState = 'error';
                            this.errorMessage = resData.message || @js(__('orders.pay_error_initiation'));
                            return;
                        }

                        this.handlePayResponse(resData.data || resData);
                    } catch (err) {
                        this.paymentState = 'error';
                        this.errorMessage = @js(__('orders.pay_error_network'));
                    }
                },

                handlePayResponse(sessionData) {
                    this.session = sessionData;
                    const status = sessionData.status;

                    if (status === 'confirmed') {
                        this.paymentState = 'success';
                        setTimeout(() => { window.location.reload(); }, 2000);
                    } else if (status === 'failed') {
                        this.paymentState = 'error';
                        this.failureCode = sessionData.payment_payload?.failure_code || null;
                        this.errorMessage = this.failureCopy[sessionData.payment_payload?.failure_code] || sessionData.payment_payload?.failure_reason || @js(__('orders.pay_error_incomplete'));
                    } else if (status === 'awaiting_customer_action') {
                        const action = sessionData.payment_payload?.action;
                        if (action === 'pin') {
                            this.paymentState = 'action_pin';
                            this.pinValue = '';
                        } else if (action === 'otp') {
                            this.paymentState = 'action_otp';
                            this.otpValue = '';
                            this.actionMessage = sessionData.payment_payload?.message || @js(__('orders.pay_otp_sent'));
                        } else if (action === 'redirect') {
                            this.paymentState = 'action_3ds';
                            this.startStatusPolling();
                        }
                    } else if (status === 'awaiting_transfer') {
                        this.paymentState = 'awaiting_transfer';
                        this.bankDetails = sessionData.payment_payload?.bank_details || sessionData.payment_payload || null;
                        this.startStatusPolling();
                    } else if (status === 'awaiting_confirmation') {
                        this.paymentState = 'awaiting_confirmation';
                        this.actionMessage = sessionData.payment_payload?.message || @js(__('orders.pay_momo_accept_prompt'));
                        this.startStatusPolling();
                    }
                },

                startStatusPolling() {
                    if (this.pollInterval) clearInterval(this.pollInterval);
                    this.pollInterval = setInterval(async () => {
                        try {
                            let res = await fetch(`/api/payment-sessions/${this.session.id}/status`);
                            let data = await res.json();
                            if (data.status === 'confirmed') {
                                clearInterval(this.pollInterval);
                                this.paymentState = 'success';
                                setTimeout(() => { window.location.reload(); }, 2000);
                            } else if (data.status === 'failed') {
                                clearInterval(this.pollInterval);
                                this.paymentState = 'error';
                                this.failureCode = data.payment_payload?.failure_code || null;
                                this.errorMessage = this.failureCopy[data.payment_payload?.failure_code] || data.payment_payload?.failure_reason || @js(__('orders.pay_error_failed'));
                            }
                        } catch (e) {}
                    }, 4500);
                },

                copyToClipboard(text) {
                    navigator.clipboard.writeText(text);
                    alert(@js(__('orders.pay_copied_alert')));
                }
            }"
            class="mt-6 rounded-[12px] bg-white p-6 shadow-sm shadow-zinc-900/5 sm:p-8"
        >
            <!-- Select Method -->
            <div x-show="paymentState === 'select_method'">
                <h3 class="text-base font-bold text-zinc-900 text-center">{{ __('orders.pay_heading') }}</h3>
                <p class="text-xs text-zinc-500 text-center mb-6">{{ __('orders.pay_choose_method', ['number' => $order->order_number]) }}</p>

                <div class="grid grid-cols-1 gap-3">
                    <template x-for="method in session?.available_methods" :key="method.type">
                        <button
                            type="button"
                            @click="selectPaymentMethod(method)"
                            class="flex items-center gap-3 w-full p-4 border border-zinc-200 rounded-[12px] hover:border-blue-500 hover:bg-blue-50/30 text-left transition duration-150"
                        >
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-900 font-bold text-xs uppercase" x-text="method.type.substring(0,2)"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-zinc-950" x-text="method.label"></p>
                                <p class="text-xs text-zinc-500 truncate" x-text="method.description"></p>
                            </div>
                            <svg class="h-5 w-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </template>
                </div>
            </div>

            <!-- Card Details Form -->
            <div x-show="paymentState === 'card_input'">
                <div class="flex items-center gap-2 mb-4">
                    <button type="button" @click="paymentState = 'select_method'" class="text-zinc-500 hover:text-zinc-800 text-xs flex items-center gap-1">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg> {{ __('common.back') }}
                    </button>
                </div>
                <h3 class="text-sm font-bold text-zinc-900 mb-4">{{ __('orders.pay_card_heading') }}</h3>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700">{{ __('orders.pay_cardholder_name') }}</label>
                        <input type="text" x-model="cardDetails.card_holder" placeholder="{{ __('orders.pay_cardholder_placeholder') }}" class="w-full mt-1.5 rounded-full border-2 border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 focus:border-edge dark:focus:border-edge transition-colors">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-zinc-700">{{ __('orders.pay_card_number') }}</label>
                        <input type="text" @input="formatCardNumber" x-model="cardDetails.card_number" maxlength="19" placeholder="0000 0000 0000 0000" class="w-full mt-1.5 rounded-full border-2 border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 focus:border-edge dark:focus:border-edge transition-colors">
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold text-zinc-700">{{ __('orders.pay_expiry') }}</label>
                            <div class="flex gap-2">
                                <input type="text" x-model="cardDetails.expiry_month" placeholder="{{ __('orders.pay_expiry_month_placeholder') }}" maxlength="2" class="w-full mt-1.5 rounded-full border-2 border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 text-center focus:border-edge dark:focus:border-edge transition-colors">
                                <input type="text" x-model="cardDetails.expiry_year" placeholder="{{ __('orders.pay_expiry_year_placeholder') }}" maxlength="2" class="w-full mt-1.5 rounded-full border-2 border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 text-center focus:border-edge dark:focus:border-edge transition-colors">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 text-center">CVV</label>
                            <input type="password" x-model="cardDetails.cvv" placeholder="123" maxlength="4" class="w-full mt-1.5 rounded-full border-2 border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 text-center focus:border-edge dark:focus:border-edge transition-colors">
                        </div>
                    </div>

                    <button @click="paySession('card', cardDetails)" class="w-full rounded-full bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700 mt-2">
                        {{ __('orders.pay_button') }} <span x-text="session?.display_currency + ' ' + Number(session?.amount).toFixed(2)"></span>
                    </button>
                </div>
            </div>

            <!-- Card Auth: PIN Challenge -->
            <div x-show="paymentState === 'action_pin'">
                <h3 class="text-sm font-bold text-zinc-900 mb-2">{{ __('orders.pay_pin_heading') }}</h3>
                <p class="text-xs text-zinc-600 mb-4">{{ __('orders.pay_pin_help') }}</p>
                <div class="space-y-4">
                    <input type="password" x-model="pinValue" maxlength="4" placeholder="••••" class="w-full rounded-full border-2 border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 focus:border-edge dark:focus:border-edge transition-colors">
                    <button @click="paySession('card', cardDetails)" class="w-full rounded-full bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                        {{ __('orders.pay_pin_confirm') }}
                    </button>
                </div>
            </div>

            <!-- Card Auth: OTP Challenge -->
            <div x-show="paymentState === 'action_otp'">
                <h3 class="text-sm font-bold text-zinc-900 mb-2">{{ __('orders.pay_otp_heading') }}</h3>
                <p class="text-xs text-zinc-600 mb-4" x-text="actionMessage"></p>
                <div class="space-y-4">
                    <input type="text" x-model="otpValue" placeholder="123456" class="w-full rounded-full border-2 border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900 focus:border-edge dark:focus:border-edge transition-colors">
                    <button @click="paySession('card', cardDetails)" class="w-full rounded-full bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                        {{ __('orders.pay_otp_verify') }}
                    </button>
                </div>
            </div>

            <!-- Card Auth: 3D Secure Redirect -->
            <div x-show="paymentState === 'action_3ds'">
                <h3 class="text-sm font-bold text-zinc-900 mb-2">{{ __('orders.pay_3ds_heading') }}</h3>
                <p class="text-xs text-zinc-600 mb-4">{{ __('orders.pay_3ds_help') }}</p>
                
                <div class="w-full border border-zinc-200 rounded-[12px] overflow-hidden bg-zinc-50" style="height: 380px;">
                    <iframe :src="session?.payment_payload?.redirect_url" class="w-full h-full border-0"></iframe>
                </div>

                <button @click="startStatusPolling()" class="w-full mt-4 rounded-full bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                    {{ __('orders.pay_3ds_done') }}
                </button>
            </div>

            <!-- Mobile Money input -->
            <div x-show="paymentState === 'momo_input'">
                <div class="flex items-center gap-2 mb-4">
                    <button type="button" @click="paymentState = 'select_method'" class="text-zinc-500 hover:text-zinc-800 text-xs flex items-center gap-1">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg> {{ __('common.back') }}
                    </button>
                </div>
                <h3 class="text-sm font-bold text-zinc-900 mb-4">{{ __('orders.pay_momo_heading') }}</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700">{{ __('orders.pay_momo_phone') }}</label>
                        <input type="text" x-model="momoDetails.phone_number" placeholder="{{ __('orders.pay_momo_phone_placeholder') }}" class="w-full mt-1.5 rounded-full border-2 border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 focus:border-edge dark:focus:border-edge transition-colors">
                    </div>

                    <div x-show="(selectedMethod?.supported_networks ?? []).length > 0">
                        <label class="block text-xs font-semibold text-zinc-700">{{ __('orders.pay_momo_network') }}</label>
                        <div class="relative mt-1.5">
                            <select x-model="momoDetails.network" class="w-full appearance-none rounded-full border-2 border-zinc-200 bg-white py-2.5 pl-3 pr-9 text-sm font-medium text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 focus:border-edge dark:focus:border-edge">
                                <template x-for="net in selectedMethod?.supported_networks" :key="net">
                                    <option :value="net" x-text="net"></option>
                                </template>
                            </select>
                            <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                    </div>

                    <button @click="paySession('mobile_money', momoDetails)" class="w-full rounded-full bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                        {{ __('orders.pay_button') }} <span x-text="session?.display_currency + ' ' + Number(session?.amount).toFixed(2)"></span>
                    </button>
                </div>
            </div>

            <!-- Bank Transfer virtual accounts display or Crypto invoice -->
            <div x-show="paymentState === 'awaiting_transfer'">
                <!-- If Bank Transfer details -->
                <template x-if="selectedMethod?.type === 'bank_transfer' || session?.payment_payload?.bank_details">
                    <div>
                        <h3 class="text-sm font-bold text-zinc-900 mb-2">{{ __('orders.pay_bank_heading') }}</h3>
                        <p class="text-xs text-zinc-600 mb-4">{{ __('orders.pay_bank_help') }}</p>

                        <div class="bg-zinc-50 border border-zinc-200 rounded-[12px] p-4 space-y-3">
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-zinc-500">{{ __('orders.pay_bank_name') }}</span>
                                <span class="font-bold text-zinc-900" x-text="bankDetails?.bank_name"></span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-zinc-500">{{ __('orders.pay_bank_account_number') }}</span>
                                <div class="flex items-center gap-1.5">
                                    <span class="font-bold text-zinc-900 text-sm" x-text="bankDetails?.account_number"></span>
                                    <button type="button" @click="copyToClipboard(bankDetails?.account_number)" class="text-blue-600 hover:text-blue-800 text-[10px] font-semibold">{{ __('common.copy') }}</button>
                                </div>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-zinc-500">{{ __('orders.pay_bank_account_name') }}</span>
                                <span class="font-bold text-zinc-900" x-text="bankDetails?.account_name"></span>
                            </div>
                            <div class="flex justify-between items-center text-xs border-t border-zinc-200 pt-2">
                                <span class="text-zinc-500">{{ __('orders.pay_bank_amount') }}</span>
                                <span class="font-extrabold text-blue-700 text-sm" x-text="session?.currency + ' ' + Number(bankDetails?.amount || session?.amount).toFixed(2)"></span>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- If Crypto details -->
                <template x-if="selectedMethod?.type === 'crypto' || session?.payment_payload?.qr_payload">
                    <div>
                        <h3 class="text-sm font-bold text-zinc-900 text-center mb-2">{{ __('orders.pay_crypto_heading') }}</h3>
                        <p class="text-xs text-zinc-600 text-center mb-4">{{ __('orders.pay_crypto_help') }}</p>

                        <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start bg-zinc-50 p-4 border border-zinc-200 rounded-[12px]">
                            <!-- QR Code -->
                            <div class="flex shrink-0 flex-col items-center rounded-[12px] bg-white p-2 border border-zinc-150">
                                {{-- Rendered by us. This used to call a third-party image
                                     service, which sent the customer's deposit address
                                     off-site and would have let a compromise there swap
                                     the address shown for an attacker's. --}}
                                <img
                                    :src="session?.id ? ('/api/payment-sessions/' + session.id + '/crypto-qr') : ''"
                                    alt="{{ __('orders.pay_crypto_qr_alt') }}"
                                    class="h-28 w-28 object-contain"
                                />
                                <span class="mt-1 text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ __('orders.pay_crypto_scan') }}</span>
                            </div>

                            <!-- Details -->
                            <div class="flex-1 w-full space-y-2 text-xs">
                                <div>
                                    <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block">{{ __('orders.pay_crypto_currency') }}</span>
                                    <div class="flex items-center gap-1.5">
                                        <span class="rounded-[12px] bg-blue-50 px-2 py-0.5 font-bold text-blue-700 uppercase" x-text="session?.payment_payload?.pay_currency || 'btc'"></span>
                                        <span class="text-[10px] font-medium text-zinc-500 uppercase" x-text="@js(__('orders.pay_crypto_network')).replace(':network', session?.payment_payload?.network || 'bitcoin')"></span>
                                    </div>
                                </div>
                                <div>
                                    <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block">{{ __('orders.pay_crypto_amount') }}</span>
                                    <span class="font-bold text-zinc-900 text-sm" x-text="session?.payment_payload?.pay_amount"></span>
                                    <span class="font-bold text-zinc-500 uppercase" x-text="session?.payment_payload?.pay_currency"></span>
                                    <span class="block text-[10px] text-zinc-500">Network fees included. Send this exact amount.</span>
                                </div>
                                <div>
                                    <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block">{{ __('orders.pay_crypto_address') }}</span>
                                    <div class="mt-1 flex items-center gap-1">
                                        <input type="text" readonly :value="session?.payment_payload?.pay_address" class="w-full bg-zinc-100 px-2 py-1 rounded-full text-[10px] text-zinc-800 font-mono select-all outline-none border-2 border-zinc-200 dark:border-white/10 focus:border-edge dark:focus:border-edge transition-colors">
                                        <button type="button" @click="copyToClipboard(session?.payment_payload?.pay_address)" class="text-blue-600 hover:text-blue-800 text-[10px] font-semibold shrink-0">{{ __('common.copy') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <div class="flex flex-col items-center mt-5 text-center">
                    <svg class="h-5 w-5 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-xs font-semibold text-zinc-800 mt-2">{{ __('orders.pay_waiting_transfer') }}</p>
                    <p class="text-[10px] text-zinc-500 mt-1">{{ __('orders.pay_waiting_transfer_help') }}</p>
                </div>
            </div>

            <!-- Mobile money push prompt -->
            <div x-show="paymentState === 'awaiting_confirmation'">
                <h3 class="text-sm font-bold text-zinc-900 mb-2">{{ __('orders.pay_authorize_heading') }}</h3>
                <p class="text-xs text-zinc-600 mb-4" x-text="actionMessage"></p>

                <div class="flex flex-col items-center py-6 text-center">
                    <span class="flex h-14 w-14 items-center justify-center rounded-[12px] bg-blue-50 ring-8 ring-blue-100/50 mb-4">
                        <svg class="h-7 w-7 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                    </span>
                    <svg class="h-6 w-6 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-xs font-semibold text-zinc-800 mt-3">{{ __('orders.pay_verifying') }}</p>
                </div>
            </div>

            <!-- Processing state -->
            <div x-show="paymentState === 'processing'" class="flex flex-col items-center py-8 text-center">
                <svg class="h-10 w-10 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="mt-4 text-sm font-bold text-zinc-900">{{ __('orders.pay_processing') }}</p>
                <p class="mt-1 text-xs text-zinc-500">{{ __('orders.pay_do_not_close') }}</p>
            </div>

            <!-- Success state: the same animated tick as the order-complete
                 hero so the customer sees one consistent celebration. -->
            <div x-show="paymentState === 'success'" class="flex flex-col items-center py-10 text-center">
                <x-success-tick />
                <h3 class="mt-5 text-2xl font-bold text-zinc-900 dark:text-white">{{ __('orders.pay_complete_heading') }}</h3>
                <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">{{ __('orders.pay_complete_help') }}</p>
            </div>

            <!-- Error state: animated red cross, the tick's counterpart. -->
            <div x-show="paymentState === 'error'" class="flex flex-col items-center py-6 text-center">
                <x-error-cross />
                <h3 class="mt-4 text-sm font-bold text-zinc-900">{{ __('orders.pay_failed_heading') }}</h3>
                <p class="mt-1.5 text-xs text-red-600 px-4" x-text="errorMessage"></p>

                @include('shop._payment-failure-help', ['methodVar' => 'selectedMethod'])

                <button type="button" @click="paymentState = 'select_method'" class="mt-6 rounded-full bg-zinc-100 px-5 py-2.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-200">
                    {{ __('orders.pay_try_another') }}
                </button>
            </div>
        </section>
    @endif

    {{-- Order summary --}}
    <section class="mt-6 rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/5 sm:p-6">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold text-zinc-900">{{ __('checkout.order_summary') }}</h2>
            <span class="inline-flex items-center rounded-[12px] px-2.5 py-1 text-xs font-bold ring-1 {{ $statusBadge }}">
                {{ $awaitingPayment ? __('orders.status_awaiting_payment') : ($expiredCheckout ? __('orders.status_expired') : $statusLabel) }}
            </span>
        </div>

        {{-- Meta rows --}}
        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-xs font-medium text-zinc-500">{{ __('orders.date_placed') }}</dt>
                <dd class="mt-0.5 font-semibold text-zinc-900">{{ ($order->placed_at ?? $order->created_at)->format('M j, Y') }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-zinc-500">{{ __('checkout.payment_method') }}</dt>
                <dd class="mt-0.5 font-semibold text-zinc-900">{{ $paymentMethod }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-zinc-500">{{ __('orders.items') }}</dt>
                <dd class="mt-0.5 font-semibold text-zinc-900">{{ $order->items->count() }}</dd>
            </div>
        </dl>

        {{-- Line items — grouped by variant so 2× Apple $5 renders as one row --}}
        @php $groupedItems = $order->items->groupBy('product_variant_id'); @endphp
        <ul class="mt-5 divide-y divide-zinc-100 border-t border-zinc-100">
            @foreach ($groupedItems as $variantId => $variantItems)
                @php
                    $firstItem = $variantItems->first();
                    $groupQty  = $variantItems->count();
                    $groupTotal = $variantItems->sum('subtotal_amount');
                    // product_snapshot is the catalog Product captured at order time.
                    $snap     = $firstItem->product_snapshot ?? [];
                    $brandKey = $snap['brand_key'] ?? null;
                    $name     = $brandKey ? Product::brandDisplayName($brandKey) : ($snap['name'] ?? __('orders.item_fallback_name'));
                    $logo     = Product::brandLogoUrl($brandKey, $snap['logo_url'] ?? null);
                    // Snapshot didn't always include the category before today, so
                    // fall back to the live FK relation for historical orders.
                    $itemCategory = (string) ($snap['category']['slug'] ?? $item->category?->slug ?? '');
                    $itemIsTopup = $itemCategory === 'mobile-airtime';
                    $itemIsEsim  = $itemCategory === 'esims';
                    // Pending copy varies by product: top-ups credit a phone,
                    // eSIMs deliver a QR, gift cards email a code.
                    $itemPendingCopy = match (true) {
                        $itemIsTopup => __('orders.pending_topup'),
                        $itemIsEsim  => __('orders.pending_esim'),
                        default      => __('orders.pending_code'),
                    };
                    $itemMeta = (array) ($item->metadata ?? []);
                    $itemRecipientPhone = is_scalar($itemMeta['recipient_phone'] ?? null)
                        ? (string) $itemMeta['recipient_phone']
                        : '';
                @endphp
                <li class="flex items-start gap-3 py-4">
                    {{-- Brand tile — matches the catalog / cart product card (16:10, edge-to-edge logo). --}}
                    <span class="flex aspect-[16/10] w-24 shrink-0 items-center justify-center overflow-hidden rounded-[6px] bg-white shadow-sm ring-1 ring-zinc-200 sm:w-28">
                        @if ($logo)
                            <img src="{{ $logo }}" alt="" class="h-full w-full object-cover" loading="lazy">
                        @else
                            <span class="text-lg font-black uppercase text-zinc-700">{{ str($name)->substr(0, 2)->upper() }}</span>
                        @endif
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-bold text-zinc-900">{{ $name }}</p>
                        <p class="mt-0.5 text-xs text-zinc-600">
                            {{ __('orders.line_qty', ['qty' => $groupQty]) }} &middot; {{ __('orders.line_each', ['price' => $money($firstItem->display_amount)]) }}
                        </p>
                        {{-- Render redemption details for each individual unit (each has its own unique code) --}}
                        @foreach ($variantItems as $item)
                            @if (! empty($item->fulfillment_payload))
                                <div class="mt-2 rounded-[12px] bg-zinc-50 px-3 py-2 ring-1 ring-zinc-200">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ $groupQty > 1 ? __('orders.code_n_of_total', ['current' => $loop->iteration, 'total' => $groupQty]) : __('orders.redemption_details') }}</p>
                                    
                                    @if(!empty($item->fulfillment_payload['phone_number']))
                                        <div class="mt-2 mb-2 flex items-center gap-2 rounded-[12px] bg-blue-100 px-3 py-2 text-blue-800">
                                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                            </svg>
                                            <span class="text-sm font-bold tracking-wide">{{ $item->fulfillment_payload['phone_number'] }}</span>
                                        </div>
                                    @endif

                                    @if(!empty($item->fulfillment_payload['qrcode_url']))
                                        <div class="mt-2 mb-2">
                                            <img src="{{ $item->fulfillment_payload['qrcode_url'] }}" alt="{{ __('orders.esim_qr_short_alt') }}" class="h-32 w-32 rounded-[12px] bg-white p-2 ring-1 ring-zinc-200">
                                        </div>
                                    @endif

                                    @foreach ((array) $item->fulfillment_payload as $key => $value)
                                        @if (is_scalar($value) && !in_array($key, ['raw_response', 'qrcode_url', 'phone_number']))
                                            <p class="mt-1 text-sm font-bold tabular-nums text-zinc-900 break-all">
                                                <span class="text-[10px] text-zinc-500 font-normal uppercase">{{ str_replace('_', ' ', $key) }}:</span>
                                                {{ $value }}
                                            </p>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                        @if ($variantItems->some(fn ($i) => $i->fulfillment_status?->value === 'failed'))
                            <p class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-red-600">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                                {{ __('orders.fulfillment_failed') }}
                            </p>
                        @elseif ($variantItems->every(fn ($i) => empty($i->fulfillment_payload)))
                            <p class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-zinc-500">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                {{ $itemPendingCopy }}{{ $itemIsTopup && $itemRecipientPhone ? ' ('.$itemRecipientPhone.')' : '' }}
                            </p>
                        @endif
                    </div>

                    <span class="shrink-0 text-sm font-bold tabular-nums text-zinc-900">{{ $money($groupTotal) }}</span>
                </li>
            @endforeach
        </ul>

        {{-- Totals --}}
        <div class="mt-1 space-y-2 border-t border-zinc-100 pt-4 text-sm">
            <div class="flex items-center justify-between text-base font-bold text-zinc-900">
                <span>{{ __('orders.total_paid') }}</span>
                <span class="tabular-nums">{{ $money($order->total_amount) }}</span>
            </div>
            @if ($rcoinEnabled)
                <div class="flex items-center justify-between pt-1 text-zinc-600">
                    <span class="inline-flex items-center gap-1.5">
                        {{ __('orders.points_earned') }}
                        <img src="{{ asset('assets/favicon.ico') }}" alt="" class="h-4 w-4 object-contain">
                    </span>
                    <span class="font-bold tabular-nums text-zinc-900">{{ number_format($points) }}</span>
                </div>
            @endif
        </div>
    </section>

    {{-- What happens next — only while the order is still being settled --}}
    @if ($isPending)
        <section class="mt-6 rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/5 sm:p-6">
            <h2 class="text-lg font-bold text-zinc-900">{{ __('orders.next_heading') }}</h2>
            <ol class="mt-4 space-y-4">
                @php
                    // Order content drives the wording. A pure top-up order
                    // shouldn't say "codes will be emailed" — nothing is.
                    $fulfilLabel = $orderHasGiftCards
                        ? __('orders.next_codes_title')
                        : ($orderHasEsims ? __('orders.next_esim_title') : __('orders.next_topup_title'));
                    $fulfilDesc = $orderHasGiftCards
                        ? __('orders.next_codes_body')
                        : ($orderHasEsims
                            ? __('orders.next_esim_body')
                            : __('orders.next_topup_body'));
                    $deliveryLabel = $orderHasGiftCards
                        ? __('orders.next_delivery_codes_title')
                        : ($orderHasEsims ? __('orders.next_delivery_esim_title') : __('orders.next_delivery_topup_title'));
                    $deliveryTo = ['email' => $deliveryEmail ?: __('orders.next_your_email')];
                    $deliveryDesc = $orderHasGiftCards
                        ? __('orders.next_delivery_codes_body', $deliveryTo)
                        : ($orderHasEsims
                            ? __('orders.next_delivery_esim_body', $deliveryTo)
                            : __('orders.next_delivery_topup_body', $deliveryTo));
                @endphp
                @foreach ([
                    [__('orders.next_confirm_title'), __('orders.next_confirm_body')],
                    [$fulfilLabel, $fulfilDesc],
                    [$deliveryLabel, $deliveryDesc],
                ] as $i => $step)
                    <li class="flex gap-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[12px] bg-blue-50 text-xs font-bold text-blue-700 ring-1 ring-blue-100">{{ $i + 1 }}</span>
                        <div>
                            <p class="text-sm font-semibold text-zinc-900">{{ $step[0] }}</p>
                            <p class="mt-0.5 text-sm text-zinc-600">{{ $step[1] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- Actions --}}
    <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <a href="{{ route('dashboard.orders') }}" wire:navigate
            class="flex items-center justify-center rounded-full border-2 border-blue-600 bg-white px-4 py-3 text-base font-semibold text-blue-600 transition-colors hover:bg-blue-600 hover:text-white">
            {{ __('orders.go_to_orders') }}
        </a>
        <a href="{{ $shopRoute('gift-cards') }}" wire:navigate
            class="flex items-center justify-center rounded-full bg-blue-600 px-4 py-3 text-base font-semibold text-white transition-colors hover:bg-blue-700">
            {{ __('orders.continue_shopping') }}
        </a>
    </div>

    <p class="mt-5 flex items-center justify-center gap-1.5 text-center text-xs text-zinc-600">
        <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        {{ __('orders.need_help', ['reference' => $order->order_number]) }}
    </p>

</div>
</div>

@endif

@if ($isPending)
    {{-- Fulfillment watcher: while the order is pending/processing, poll the
         status probe and swap this page for the completed (or failed) view
         the moment fulfillment lands - the customer never reloads by hand.
         Capped at ~10 minutes; past that the email/dashboard take over. --}}
    <script>
        (function () {
            const url = @js(route('shop.order.status', $order->order_number));
            let ticks = 0;
            const timer = setInterval(async () => {
                if (++ticks > 150) { clearInterval(timer); return; }
                try {
                    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (! res.ok) { return; }
                    const data = await res.json();
                    if (data.order_status && ! ['pending', 'processing'].includes(data.order_status)) {
                        clearInterval(timer);
                        window.location.reload();
                    }
                } catch (e) { /* transient network blip - keep polling */ }
            }, 4000);
        })();
    </script>
@endif
</x-shop.layout>
