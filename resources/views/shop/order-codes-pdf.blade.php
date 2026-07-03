{{--
    Printable PDF receipt for an order's redemption codes. Rendered server-side
    via barryvdh/laravel-dompdf, downloaded by the "Add to wallet" button on
    the order success view. Kept intentionally narrow on styling because DomPDF
    only supports a small CSS subset (no flexbox, limited grid).

    @var \App\Models\Order $order
--}}
@php
    use App\Models\Product;

    $sym = Product::currencySymbol($order->settlement_currency ?: 'USD');
    $money = fn ($v) => $sym . number_format((float) $v, 2);
    $placedAt = ($order->placed_at ?? $order->created_at)->format('M j, Y g:i A');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $siteName }} receipt {{ $order->order_number }}</title>
    <style>
        @page { margin: 32px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0c1a2e; line-height: 1.4; }
        .header { border-bottom: 2px solid #0c1a2e; padding-bottom: 12px; margin-bottom: 18px; }
        .brand { font-size: 18px; font-weight: 700; }
        .meta { color: #52525b; font-size: 10px; margin-top: 4px; }
        h1 { font-size: 14px; margin: 18px 0 8px; }
        .item { border: 1px solid #e4e4e7; border-radius: 6px; padding: 12px; margin-bottom: 10px; }
        .item-head { font-weight: 700; font-size: 12px; margin-bottom: 4px; }
        .item-sub { color: #52525b; font-size: 10px; margin-bottom: 8px; }
        .code-row { background: #f4f4f5; border: 1px solid #d4d4d8; padding: 8px 10px; margin-top: 6px; font-family: DejaVu Sans Mono, monospace; font-size: 12px; font-weight: 700; letter-spacing: 0.5px; word-break: break-all; }
        .code-label { display: block; font-family: DejaVu Sans, sans-serif; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #71717a; font-weight: 600; margin-bottom: 3px; }
        .footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e4e4e7; color: #71717a; font-size: 9px; line-height: 1.5; }
        .totals { margin-top: 14px; font-size: 11px; }
        .totals td { padding: 3px 0; }
        .totals .label { color: #52525b; }
        .totals .value { text-align: right; font-weight: 700; }
    </style>
</head>
<body>

    <div class="header">
        <div class="brand">{{ $siteName }}</div>
        <div class="meta">
            Order receipt &middot; #{{ $order->order_number }} &middot; {{ $placedAt }}
        </div>
    </div>

    <h1>Your redemption codes</h1>

    @foreach ($order->items as $item)
        @php
            $snap = $item->product_snapshot ?? [];
            $brandKey = $snap['brand_key'] ?? null;
            $name = $brandKey ? Product::brandDisplayName($brandKey) : ($snap['name'] ?? 'Item');
            $region = $snap['country_code'] ?? '';
            $payload = (array) ($item->fulfillment_payload ?? []);
            $codes = [];
            foreach ($payload as $key => $value) {
                if (is_scalar($value) && ! in_array($key, ['raw_response', 'qrcode_url', 'phone_number'], true)) {
                    $codes[ucwords(str_replace('_', ' ', $key))] = (string) $value;
                }
            }
        @endphp
        <div class="item">
            <div class="item-head">{{ $name }}</div>
            <div class="item-sub">
                {{ $money($item->display_amount) }}{{ $region ? ' &middot; ' . $region : '' }}
            </div>
            @if (count($codes) > 0)
                @foreach ($codes as $label => $value)
                    <span class="code-label">{{ $label }}</span>
                    <div class="code-row">{{ $value }}</div>
                @endforeach
            @else
                <div class="item-sub">Code not yet delivered. Check the order page online.</div>
            @endif
        </div>
    @endforeach

    <table class="totals" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td class="label">Items</td>
            <td class="value">{{ $order->items->count() }}</td>
        </tr>
        <tr>
            <td class="label">Total paid</td>
            <td class="value">{{ $money($order->total_amount) }}</td>
        </tr>
    </table>

    <div class="footer">
        Keep this receipt safe - the codes above are equivalent to cash. {{ $siteName }} cannot reissue redemption codes that have been viewed, shared, or used. For questions about a code, reply to your order email or visit your account dashboard.
    </div>

</body>
</html>
