{{--
    Order Report (PDF) — generated from the admin order page's "Generate
    Report" button. Assembled by OrderReportService from the immutable stores;
    suitable for dispute/representment and acquirer review.

    Design: modern receipt — official logo on a light header, amount hero,
    soft cards, colored status pills. DomPDF subset only: tables, no flexbox.

    @var array $data  OrderReportService::assemble() output
--}}
@php
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('M j, Y H:i') . ' UTC' : '—';
    $usd = fn ($v) => '$' . number_format((float) $v, 2);
    $r = $data['report'];
    $o = $data['order'];
    $c = $data['customer'];

    // Status pill colors: [background, text]. Green = money/goods settled,
    // red = terminal failure, gray = neutral/undone, amber = in flight.
    $pill = function (?string $status): array {
        $s = strtolower((string) $status);
        return match (true) {
            in_array($s, ['paid', 'completed', 'fulfilled', 'delivered', 'verified', 'sent', 'allow']) => ['#dcfce7', '#166534'],
            in_array($s, ['failed', 'expired', 'rejected', 'cancelled', 'deny', 'banned']) => ['#fee2e2', '#991b1b'],
            in_array($s, ['refunded', 'partially_refunded', 'reversed']) => ['#e4e4e7', '#3f3f46'],
            default => ['#fef3c7', '#92400e'],
        };
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Order Report {{ $o['number'] }}</title>
    <style>
        @page { margin: 0 0 46px 0; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #18181b; line-height: 1.5; margin: 0; }

        /* Header: official logo on white, closed by a brand rule. */
        .band { padding: 26px 40px 18px; border-bottom: 3px solid #1d4ed8; }
        .band .doc { color: #1d4ed8; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; }
        .band .meta { color: #64748b; font-size: 8.5px; text-align: right; line-height: 1.6; }

        .sheet { padding: 22px 40px 0; }

        /* Amount hero */
        .hero { background: #f4f6f5; border: 1px solid #dfe3e1; border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; }
        .hero .label { color: #3b82f6; font-size: 8px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 700; }
        .hero .amount { font-size: 21px; font-weight: 700; color: #0c1a2e; margin-top: 2px; }
        .hero .sub { color: #64748b; font-size: 8.5px; margin-top: 2px; }
        .hero td { vertical-align: middle; }

        /* Sections */
        h2 { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.4px; color: #1d4ed8; margin: 20px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #e2e8f0; }

        /* Cards + tables */
        .card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 4px 0; }
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 4.5px 14px; vertical-align: top; }
        table.kv tr + tr td { border-top: 1px solid #f1f5f9; }
        table.kv td.k { width: 30%; color: #64748b; }
        table.kv td.v { font-weight: 600; color: #18181b; }

        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { text-align: left; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; padding: 6px 10px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        table.grid td { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        table.grid tr:last-child td { border-bottom: none; }
        .gridwrap { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }

        .pill { display: inline-block; border-radius: 8px; padding: 1.5px 7px; font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 8px; }
        .muted { color: #94a3b8; }
        .num { text-align: right; }
        .empty { color: #a1a1aa; font-style: italic; padding: 8px 12px; }

        .footer { position: fixed; bottom: -34px; left: 0; right: 0; background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 9px 40px; color: #94a3b8; font-size: 7.5px; }
    </style>
</head>
<body>
    <div class="band">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td>
                    {{-- JPEG deliberately: DomPDF embeds JPEG without the GD
                         extension, PNG requires it. White-flattened, so it is
                         visually identical on this light header. --}}
                    <img src="{{ public_path('assets/email-logo.jpg') }}" style="height: 30px;" alt="RshopRefill">
                    <div class="doc" style="margin-top: 7px;">Order Report</div>
                </td>
                <td class="meta">
                    <strong style="color:#0c1a2e; font-size:10.5px;">{{ $o['number'] }}</strong><br>
                    Generated {{ $fmt($r['generated_at']) }}<br>
                    by {{ $r['generated_by'] }}
                </td>
            </tr>
        </table>
    </div>

    <div class="sheet">
        {{-- Amount hero: the receipt facts at a glance --}}
        @php [$obg, $ofg] = $pill($o['payment_status']); @endphp
        <div class="hero">
            <table style="width:100%; border-collapse:collapse;">
                <tr>
                    <td>
                        <div class="label">Order total</div>
                        <div class="amount">{{ $o['display_currency'] }} {{ number_format($o['total_display'], 2) }}</div>
                        <div class="sub">Settlement {{ $usd($o['total_usd']) }} USD{{ $o['exchange_rate'] ? ' · rate ' . $o['exchange_rate'] : '' }} · {{ $o['payment_method'] }}</div>
                    </td>
                    <td style="text-align:right;">
                        <span class="pill" style="background: {{ $obg }}; color: {{ $ofg }};">{{ $o['payment_status'] }}</span>
                        <div class="sub" style="margin-top:5px;">Placed {{ $fmt($o['placed_at']) }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <h2>Order</h2>
        <div class="card">
            <table class="kv">
                <tr><td class="k">Order number</td><td class="v">{{ $o['number'] }} <span class="mono muted">({{ $o['id'] }})</span></td></tr>
                @php [$osb, $osf] = $pill($o['order_status']); [$fsb, $fsf] = $pill($o['fulfillment_status']); @endphp
                <tr><td class="k">Status</td><td class="v">
                    <span class="pill" style="background: {{ $osb }}; color: {{ $osf }};">order {{ $o['order_status'] }}</span>
                    <span class="pill" style="background: {{ $obg }}; color: {{ $ofg }};">payment {{ $o['payment_status'] }}</span>
                    <span class="pill" style="background: {{ $fsb }}; color: {{ $fsf }};">fulfillment {{ $o['fulfillment_status'] }}</span>
                </td></tr>
                <tr><td class="k">Delivery email</td><td class="v">{{ $o['delivery_email'] ?? '—' }}</td></tr>
                <tr><td class="k">Device at checkout</td><td class="v mono">{{ $o['device_ip'] ?? '—' }}<br><span class="muted" style="font-size:7px;">{{ $o['user_agent'] ?? '' }}</span></td></tr>
            </table>
        </div>

        <h2>Customer &amp; verification</h2>
        @if (! empty($c['missing']))
            <div class="card"><div class="empty">No customer account is attached to this order.</div></div>
        @else
            <div class="card">
                <table class="kv">
                    <tr><td class="k">Customer</td><td class="v">{{ $c['name'] }}{!! $c['is_guest'] ? ' <span class="muted">guest shadow account</span>' : '' !!}</td></tr>
                    <tr><td class="k">Email</td><td class="v">{{ $c['email'] }}
                        @php [$eb, $ef] = $c['email_verified_at'] ? $pill('verified') : $pill('pending'); @endphp
                        <span class="pill" style="background: {{ $eb }}; color: {{ $ef }};">{{ $c['email_verified_at'] ? 'verified' : 'not verified' }}</span>
                    </td></tr>
                    {{-- Phone row parked until the phone-OTP flow ships; restore alongside PHONE_UNVERIFIED re-enable. --}}
                    <tr><td class="k">Country</td><td class="v">{{ $c['country'] ?? '—' }}</td></tr>
                    <tr><td class="k">Registered</td><td class="v">{{ $fmt($c['registered_at']) }}</td></tr>
                    @php
                        // The verification tier in plain words: identity beats
                        // email, email beats nothing.
                        $tierLabel = $c['kyc_status'] === 'verified'
                            ? 'Identity verified'
                            : ($c['email_verified_at'] ? 'Email verified' : 'Not verified');
                        [$kb, $kf] = $pill($tierLabel === 'Not verified' ? 'pending' : 'verified');
                    @endphp
                    <tr><td class="k">Identity (KYC)</td><td class="v"><span class="pill" style="background: {{ $kb }}; color: {{ $kf }};">tier: {{ $tierLabel }}</span></td></tr>
                    <tr><td class="k">Lifetime paid volume</td><td class="v">{{ $usd($c['lifetime_volume_usd']) }}</td></tr>
                    {{-- Whether the account is allowed to operate: Active, or
                         banned/suspended by an admin. Certifies the buyer was
                         not a known-bad account at report time. --}}
                    <tr><td class="k">Account status</td><td class="v">{{ $c['banned_at'] ? 'Banned since ' . $fmt($c['banned_at']) : ($c['suspended_at'] ? 'Suspended since ' . $fmt($c['suspended_at']) : 'Active') }}</td></tr>
                </table>
            </div>
        @endif

        <h2>Items &amp; fulfillment</h2>
        <div class="gridwrap">
            <table class="grid">
                <tr><th>Item</th><th>Qty</th><th class="num">Subtotal</th><th>Provider</th><th>Fulfillment</th><th>Delivered</th></tr>
                @forelse ($data['items'] as $item)
                    @php [$ib, $if] = $pill($item['fulfillment_status']); @endphp
                    <tr>
                        <td><strong>{{ $item['name'] }}</strong><br><span class="muted" style="font-size:7.5px;">{{ $item['category'] }}</span></td>
                        <td>{{ $item['quantity'] }}</td>
                        <td class="num">{{ $usd($item['subtotal_usd']) }}@if ($item['display_currency'])<br><span class="muted" style="font-size:7.5px;">{{ $item['display_currency'] }} {{ number_format($item['subtotal_display'], 2) }}</span>@endif</td>
                        <td>{{ $item['provider'] ?? '—' }}<br><span class="mono muted">{{ $item['fulfillment_reference'] ?? '' }}</span></td>
                        <td><span class="pill" style="background: {{ $ib }}; color: {{ $if }};">{{ $item['fulfillment_status'] }}</span></td>
                        <td>{{ $fmt($item['delivered_at']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No items recorded.</td></tr>
                @endforelse
            </table>
        </div>

        <h2>Payment trail</h2>
        <div class="gridwrap">
            <table class="grid">
                <tr><th>Gateway</th><th>Reference</th><th>Status</th><th class="num">Amount</th><th>Created</th><th>Confirmed / failed</th></tr>
                @forelse ($data['payments'] as $p)
                    @php [$pb, $pf] = $pill($p['status']); @endphp
                    <tr>
                        <td style="text-transform:capitalize;">{{ $p['gateway'] }}@if ($p['pay_currency'])<br><span class="muted" style="font-size:7.5px;">{{ strtoupper($p['pay_currency']) }} {{ $p['pay_amount'] }} (paid {{ $p['actually_paid'] ?? '—' }})</span>@endif</td>
                        <td class="mono">{{ $p['gateway_reference'] ?? '—' }}<br><span class="muted">session {{ $p['session_id'] ?? '—' }} ({{ $p['session_status'] ?? '—' }})</span></td>
                        <td><span class="pill" style="background: {{ $pb }}; color: {{ $pf }};">{{ $p['status'] }}</span></td>
                        <td class="num">{{ $p['currency'] }} {{ number_format($p['amount'], 2) }}</td>
                        <td>{{ $fmt($p['created_at']) }}</td>
                        <td>{{ $p['confirmed_at'] ? $fmt($p['confirmed_at']) : ($p['failed_at'] ? 'failed ' . $fmt($p['failed_at']) : '—') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No payment attempts recorded.</td></tr>
                @endforelse
            </table>
        </div>

        <h2>Communications</h2>
        <div class="gridwrap">
            <table class="grid">
                <tr><th>Channel</th><th>Category</th><th>Status</th><th class="num">Events</th><th>First</th><th>Last</th></tr>
                @forelse ($data['communications'] as $m)
                    @php [$mb, $mf] = $pill($m['status']); @endphp
                    <tr>
                        <td style="text-transform:capitalize;">{{ $m['channel'] }}</td>
                        <td>{{ $m['category'] }}</td>
                        <td><span class="pill" style="background: {{ $mb }}; color: {{ $mf }};">{{ $m['status'] }}</span></td>
                        <td class="num">{{ $m['count'] }}</td>
                        <td>{{ $fmt($m['first_at']) }}</td>
                        <td>{{ $m['count'] > 1 ? $fmt($m['last_at']) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No platform communications recorded for this order.</td></tr>
                @endforelse
            </table>
        </div>

        <h2>Login activity</h2>
        <div class="gridwrap">
            <table class="grid">
                <tr><th>Signed in</th><th>IP address</th></tr>
                @forelse ($data['audit'] as $a)
                    <tr>
                        <td>{{ $fmt($a['at']) }}</td>
                        <td class="mono">{{ $a['ip'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="empty">No sign-in recorded before this order.</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    <div class="footer">
        RshopRefill &middot; rshoprefill.com &middot; Generated {{ $fmt($r['generated_at']) }} &middot; Report {{ $r['id'] }}
    </div>
</body>
</html>
