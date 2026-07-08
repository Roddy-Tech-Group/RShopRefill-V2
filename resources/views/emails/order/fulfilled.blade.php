@php
    $snap = $item->product_snapshot ?? [];
    $vsnap = $item->variant_snapshot ?? [];
    $brandKey = $snap['brand_key'] ?? null;
    $brandName = $brandKey ? \App\Models\Product::brandDisplayName($brandKey) : ($snap['name'] ?? 'Your item');
    $logo = \App\Models\Product::brandLogoUrl($brandKey, $snap['logo_url'] ?? null);

    $faceVal = $vsnap['face_value'] ?? null;
    $faceCur = $vsnap['currency'] ?? ($snap['currency_code'] ?? 'USD');
    $faceTxt = $faceVal !== null
        ? \App\Models\Product::currencySymbol($faceCur).rtrim(rtrim(number_format((float) $faceVal, 2), '0'), '.')
        : null;

    $cc = $snap['country_code'] ?? null;
    $countryNames = array_flip(config('countries.codes', [])); // ISO -> name
    $country = $cc ? ($countryNames[strtoupper($cc)] ?? $cc) : null;
    $flag = \App\Models\Product::flagUrl($cc);

    $payload = (array) ($item->fulfillment_payload ?? []);
    $scalar = fn ($value) => (! empty($value) && is_scalar($value)) ? (string) $value : null;

    // eSIM delivery is shaped completely differently from a gift card: a QR
    // image plus SM-DP+ / activation / access credentials and a branded
    // self-service page. Same detection rule as the dashboard order list -
    // any of these payload keys flips the whole email to the eSIM layout.
    $esimManual = (array) ($payload['esim'] ?? []);
    $esim = null;
    if (! empty($payload['qrcode_url']) || ! empty($payload['lpa']) || ! empty($payload['iccid'])) {
        $esim = [
            'qr' => $scalar($payload['qrcode_url'] ?? null),
            'lpa' => $scalar($payload['lpa'] ?? null) ?? $scalar($esimManual['lpaUrl'] ?? null),
            'code' => $scalar($esimManual['manualActivationCode'] ?? null),
            'iccid' => $scalar($payload['iccid'] ?? null) ?? $scalar($esimManual['iccid'] ?? null),
            'install' => $scalar($payload['direct_install_url'] ?? null) ?? $scalar($esimManual['directInstallUrl'] ?? null),
            'manage' => $scalar($payload['sharing_link'] ?? null) ?? $scalar($esimManual['sharingLink'] ?? null),
            'access' => $scalar($payload['sharing_access_code'] ?? null) ?? $scalar($esimManual['sharingAccessCode'] ?? null),
        ];
    }

    // Mobile top-up: airtime / data / bundle is credited directly to the
    // recipient phone - there is no code or PIN, so it must NOT render as a gift
    // card (same detection rule as the dashboard order list).
    $topupPhone = $scalar($payload['topup_recipient_phone'] ?? null)
        ?? $scalar((($payload['topup'] ?? [])['recipient_phone'] ?? null))
        ?? $scalar(((array) ($item->metadata ?? []))['recipient_phone'] ?? null);
    $categorySlug = (string) ($snap['category']['slug'] ?? $item->category?->slug ?? '');
    $isTopup = ! $esim && ($categorySlug === 'mobile-airtime' || $topupPhone !== null);

    // Top-up type from the subcategory (Zendit: Mobile Top Up / Data / Bundle).
    $topupType = null;
    if ($isTopup) {
        $subcatName = strtolower((string) ($item->subcategory?->name ?? $snap['subcategory']['name'] ?? ''));
        $topupType = str_contains($subcatName, 'bundle') ? 'Bundle'
            : (str_contains($subcatName, 'data') ? 'Data' : 'Airtime');
    }

    // Gift-card code + PIN extraction. Only runs for non-eSIM, non-top-up items
    // so the fallback scalar-picker can never surface an SM-DP+ address or a
    // provider reference as a "code".
    $cardCode = null;
    $cardPin = null;
    if (! $esim && ! $isTopup) {
        // pin-redemptionURL cards (virtual prepaid Visa, etc.) carry the PIN under
        // `epin`; fall back to it so it renders as the PIN, not the generic code.
        $cardPin = $scalar($payload['pin'] ?? $payload['epin'] ?? null);
        foreach (['code', 'voucher_code', 'redeem_code', 'card_number', 'serial'] as $key) {
            if ($cardCode = $scalar($payload[$key] ?? null)) {
                break;
            }
        }
        if ($cardCode === null && $cardPin === null) {
            foreach ($payload as $value) {
                if ($cardCode = $scalar($value)) {
                    break;
                }
            }
        }
        
        // Prevent duplicate rendering
        if ($cardPin !== null && $cardCode === $cardPin) {
            $cardPin = null;
        }
    }

    $redeemHtml = $snap['redeem_instructions'] ?? null;
    // The registration/redemption link the buyer must open to claim the card.
    // Captured but historically never shown - surface it as the primary CTA.
    $redemptionUrl = $scalar($payload['redemption_url'] ?? null);

    // Rcoin cashback for this order, shown here instead of a separate
    // "you earned Rcoin" email. Prefer the credited wallet transaction
    // (exact, post-caps); fall back to the engine's preview when the rewards
    // job hasn't run yet (it is queued, and may be fraud-hold delayed).
    $order = $item->order;
    $rewardTx = \App\Models\WalletTransaction::where('idempotency_key', "reward-cashback-{$order->id}")->first();
    $rcoinEarned = $rewardTx
        ? (int) $rewardTx->amount
        : app(\App\Domain\Rewards\Services\RewardEngine::class)->cashbackPreviewFor($order);

    if ($esim) {
        $emailTitle = 'Your eSIM is ready';
        $emailPreheader = 'Your eSIM and installation details are ready to use.';
    } elseif ($isTopup) {
        $emailTitle = $topupType === 'Data' ? 'Your data is on its way'
            : ($topupType === 'Bundle' ? 'Your bundle is on its way' : 'Your top-up is on its way');
        $emailPreheader = 'Your '.strtolower($topupType).' has been credited to the phone.';
    } else {
        $emailTitle = 'Your gift card is ready';
        $emailPreheader = 'Your gift card and redemption code are ready to use.';
    }
@endphp
<x-emails.layout :mail-message="$message ?? null" :title="$emailTitle" :preheader="$emailPreheader">

    {{-- Gift deliveries land in the recipient's inbox, so the greeting names
         the buyer as the sender instead of addressing the reader by the
         buyer's name. --}}
    @if ($isGift ?? false)
        <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">{{ $emailTitle }}.</h1>

        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;"><strong>{{ $name }}</strong> sent you this as a gift. Everything you need to use it is below, keep this email somewhere safe.</p>
    @else
        <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">{{ $emailTitle }}, {{ $name }}.</h1>
    @endif

    @if ($esim)
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">{{ ($isGift ?? false) ? 'The' : 'Your' }} eSIM for order <strong>#{{ $orderNumber }}</strong> has been delivered. Scan the QR code or use the manual details below to install it, and keep this email somewhere safe.</p>
    @elseif ($isTopup)
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">{{ ($isGift ?? false) ? 'The' : 'Your' }} {{ strtolower($topupType) }} for order <strong>#{{ $orderNumber }}</strong> has been sent{{ $topupPhone ? ' to '.$topupPhone : ' to the recipient line' }}. It is credited directly to the phone - there is no code to redeem.</p>
    @else
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">{{ ($isGift ?? false) ? 'The' : 'Your' }} purchase for order <strong>#{{ $orderNumber }}</strong> has been delivered. {{ ($isGift ?? false) ? 'The' : 'Your' }} card and redemption details are below, keep this email somewhere safe.</p>
    @endif

    {{-- Product visual - mirrors the dashboard order card so it looks identical
         to what the customer sees in their account (resellers screenshot this). --}}
    @php
        // The card wears the brand's own colour (mirrors the dashboard card);
        // text flips to white on dark brands. No colour = the neutral grey.
        $brandBg = \App\Models\Product::brandColor($brandKey, $snap['brand_color'] ?? null);
        $brandDark = \App\Models\Product::colorIsDark($brandBg);
        $cardBg = $brandBg ?: '#f4f4f5';
        // Light brand colours keep the grey border so the card edge still
        // reads on the white email body; dark brands merge border into fill.
        $cardBorder = $brandDark ? $brandBg : '#e4e4e7';
        $cardInk = $brandDark ? '#ffffff' : '#18181b';
        $cardSub = $brandDark ? 'rgba(255,255,255,0.78)' : '#52525b';
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" align="center" style="width:100%; max-width:320px; margin:8px auto 0; background:{{ $cardBg }}; border:1px solid {{ $cardBorder }}; border-radius:12px;">
        <tr>
            <td style="padding:14px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td valign="top" style="font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif;">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $brandName }}" height="36" style="height:36px; width:auto; max-width:110px; display:block; border-radius:5px;">
                            @else
                                <span style="font-size:18px; font-weight:800; text-transform:uppercase; color:{{ $brandDark ? 'rgba(255,255,255,0.7)' : '#a1a1aa' }};">{{ strtoupper(substr($brandName, 0, 2)) }}</span>
                            @endif
                            @unless ($logo && \App\Models\Product::logoCarriesName($brandKey))
                                <p style="margin:8px 0 0; font-size:12px; font-weight:700; color:{{ $cardInk }};">For {{ $brandName }}</p>
                            @endunless
                        </td>
                        <td valign="top" align="right" style="font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif;">
                            @if ($faceTxt)
                                <p style="margin:0; font-size:16px; font-weight:800; line-height:1; color:{{ $cardInk }};">{{ $faceTxt }}</p>
                            @endif
                            @if ($country)
                                <p style="margin:5px 0 0; font-size:11px; color:{{ $cardSub }};">
                                    @if ($flag)<img src="{{ $flag }}" alt="" width="16" height="11" style="width:16px; height:11px; vertical-align:middle; border-radius:1px;">&nbsp;@endif{{ $country }}
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>

                {{-- Card body space --}}
                <div style="height:22px; line-height:22px; font-size:0;">&nbsp;</div>

                @if ($esim)
                    {{-- QR for scan-to-install. Email clients strip JS, so the
                         manual rows below cover devices that can't scan. --}}
                    @php
                        // Embed the QR PNG inline (CID attachment) instead of
                        // hot-linking the provider's signed URL: image proxies
                        // (Gmail) routinely fail on it, and the URL leaks the
                        // supplier's domain to the customer. $message is only
                        // set during a real send; render()/previews keep the
                        // remote URL as a best-effort fallback.
                        $esimQrSrc = $esim['qr'];
                        if ($esimQrSrc && isset($message)) {
                            try {
                                $qrDownload = \Illuminate\Support\Facades\Http::timeout(8)->get($esimQrSrc);
                                if ($qrDownload->successful() && $qrDownload->body() !== '') {
                                    $esimQrSrc = $message->embedData($qrDownload->body(), 'esim-qr.png', 'image/png');
                                }
                            } catch (\Throwable $e) {
                                // Keep the remote URL; a missing QR must never block the email.
                            }
                        }
                    @endphp
                    @if ($esimQrSrc)
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;">
                            <tr>
                                <td align="center" style="padding:14px; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif;">
                                    <img src="{{ $esimQrSrc }}" alt="eSIM installation QR code" width="170" height="170" style="width:170px; height:170px; display:block;">
                                    <p style="margin:10px 0 0; font-size:11px; color:#71717a;">Scan this QR from another device to install your eSIM.</p>
                                </td>
                            </tr>
                        </table>
                    @endif

                    @foreach (array_filter(['SM-DP+ Address' => $esim['lpa'], 'Activation code' => $esim['code'], 'ICCID' => $esim['iccid'], 'Access code' => $esim['access']]) as $credLabel => $credValue)
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;">
                            <tr>
                                <td style="padding:10px 12px; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif;">
                                    <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#71717a;">{{ $credLabel }}</span>
                                    <span style="display:block; margin-top:2px; font-size:14px; font-weight:700; letter-spacing:0.03em; color:#18181b; word-break:break-all; user-select:all; -webkit-user-select:all;">{{ $credValue }}</span>
                                </td>
                            </tr>
                        </table>
                    @endforeach

                @elseif ($isTopup)
                    {{-- Top-up: credited straight to the recipient phone, no code/PIN. --}}
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;">
                        <tr>
                            <td style="padding:12px 14px; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif;">
                                <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#71717a;">Credited to</span>
                                <span style="display:block; margin-top:2px; font-size:15px; font-weight:700; color:#18181b; word-break:break-all;">{{ $topupPhone ?? 'Your phone number on file' }}</span>
                                <span style="display:block; margin-top:6px; font-size:12px; font-weight:600; color:#16a34a;">&#10003; {{ $topupType }} sent to the line</span>
                            </td>
                        </tr>
                    </table>
                @else
                    {{-- Code + PIN. No copy button (email clients strip JS); values are
                         select-all so a tap highlights them, and the dashboard button
                         below offers true one-tap copy. --}}
                    @if ($cardCode)
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;">
                            <tr>
                                <td style="padding:10px 12px; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif;">
                                    <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#71717a;">Code</span>
                                    <span style="display:block; margin-top:2px; font-size:14px; font-weight:700; letter-spacing:0.03em; color:#18181b; word-break:break-all; user-select:all; -webkit-user-select:all;">{{ $cardCode }}</span>
                                </td>
                            </tr>
                        </table>
                    @endif
                    @if ($cardPin)
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="{{ $cardCode ? 'margin-top:8px; ' : '' }}background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;">
                            <tr>
                                <td style="padding:10px 12px; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif;">
                                    <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#71717a;">Pin</span>
                                    <span style="display:block; margin-top:2px; font-size:14px; font-weight:700; letter-spacing:0.03em; color:#18181b; word-break:break-all; user-select:all; -webkit-user-select:all;">{{ $cardPin }}</span>
                                </td>
                            </tr>
                        </table>
                    @endif
                    @if (! $cardCode && ! $cardPin)
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;">
                            <tr>
                                <td style="padding:10px 12px; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif; font-size:11px; color:#71717a;">Your code appears here once payment clears.</td>
                            </tr>
                        </table>
                    @endif
                @endif
            </td>
        </tr>
    </table>

    {{-- Cashback callout - immediately after the card / access code,
         replacing the dedicated Rcoin email. --}}
    @if ($rcoinEarned > 0)
        <x-emails.panel title="Rcoin cashback" background="#fffbeb" border="#fde68a" accent="#b45309">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#3f3f46;">
                You earned <strong style="color:#b45309;">+{{ number_format($rcoinEarned) }} Rcoin</strong> on this order.
                {{ $rewardTx ? 'It is already in your Rcoin wallet' : 'It lands in your Rcoin wallet once the order completes' }} - convert it to USD anytime from your dashboard.
            </p>
        </x-emails.panel>
    @endif

    @if ($esim)
        {{-- Action stack: install on iPhone > Manage your eSIM > Top up eSIM >
             View in your dashboard. Plain block <a> tags sized to the card
             column so they read as one unit with it across email clients. --}}
        @if ($esim['install'])
            <a href="{{ $esim['install'] }}" style="display:block; max-width:320px; margin:14px auto 0; background:#18181b; color:#ffffff; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif; font-size:14px; font-weight:600; line-height:1.2; text-align:center; text-decoration:none; padding:13px 16px; border-radius:10px;">install on iPhone</a>
        @endif
        <a href="{{ $esim['manage'] ?? url('/dashboard/orders') }}" style="display:block; max-width:320px; margin:8px auto 0; background:#2563eb; color:#ffffff; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif; font-size:14px; font-weight:600; line-height:1.2; text-align:center; text-decoration:none; padding:13px 16px; border-radius:10px;">Manage your eSIM</a>
        @if ($item->provider_name === 'airalo')
            <a href="{{ route('dashboard.esim.topup', $item) }}" style="display:block; max-width:320px; margin:8px auto 0; background:#0f766e; color:#ffffff; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif; font-size:14px; font-weight:600; line-height:1.2; text-align:center; text-decoration:none; padding:13px 16px; border-radius:10px;">Top up eSIM</a>
        @endif
        <a href="{{ url('/dashboard/orders') }}" style="display:block; max-width:320px; margin:8px auto 0; background:#64748b; color:#ffffff; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif; font-size:14px; font-weight:600; line-height:1.2; text-align:center; text-decoration:none; padding:13px 16px; border-radius:10px;">View in your dashboard</a>

        @if ($esim['lpa'])
            {{-- The SM-DP+ value looks like a URL, and mail clients auto-link
                 it - but it is a provisioning server the phone talks to, not a
                 web page. Say so before a customer taps it. --}}
            <p style="margin:14px 0 0; font-size:11px; line-height:1.6; color:#a1a1aa; text-align:center;">The SM-DP+ address is not a website. Enter it together with the activation code in Settings &gt; Cellular &gt; Add eSIM &gt; Enter Details Manually.</p>
        @endif

        @if ($redeemHtml)
            <x-emails.panel title="How to redeem">
                <div style="font-size:14px; line-height:1.6; color:#3f3f46;">{!! $redeemHtml !!}</div>
            </x-emails.panel>
        @endif

        <p style="margin:14px 0 0; font-size:13px; line-height:1.65; color:#71717a; text-align:center;">Your eSIM page has everything else you need: installation steps for your exact device, the compatible-device list, manual setup values and a downloadable PDF guide.</p>

        <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">Treat these installation details like cash. Do not share them with anyone you do not trust.</p>
    @elseif ($isTopup)
        <x-emails.button :url="url('/dashboard/orders')" align="center">View order in your dashboard</x-emails.button>

        <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">Airtime lands on the phone directly, so there is nothing to redeem. If it has not arrived within a few minutes, reply to this email and we will sort it out.</p>
    @else
        @if ($redemptionUrl)
            <x-emails.button :url="$redemptionUrl" align="center">Redeem your card</x-emails.button>
        @endif

        @if ($redeemHtml)
            <x-emails.panel title="How to redeem">
                <div style="font-size:14px; line-height:1.6; color:#3f3f46;">{!! $redeemHtml !!}</div>
            </x-emails.panel>
        @endif

        <x-emails.button :url="url('/dashboard/orders')" align="center">View &amp; copy your code</x-emails.button>

        <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">Treat this code like cash. Do not share it with anyone you do not trust.</p>
    @endif
</x-emails.layout>
