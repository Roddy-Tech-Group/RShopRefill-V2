{{--
    Transaction Report (PDF) — the financial receipt for one payment attempt:
    money facts, who paid, what it paid for. Lean by design; the order report
    carries the full order story, this one just points at it.

    DomPDF subset only: tables, no flexbox.

    @var array $data  TransactionReportService::assemble() output
--}}
@php
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('M j, Y H:i') . ' UTC' : '—';
    $usd = fn ($v) => '$' . number_format((float) $v, 2);
    $r = $data['report'];
    $t = $data['transaction'];
    $c = $data['customer'];
    $o = $data['order'];

    $pill = function (?string $status): array {
        $s = strtolower((string) $status);
        return match (true) {
            in_array($s, ['paid', 'completed', 'verified']) => ['#dcfce7', '#166534'],
            in_array($s, ['failed', 'expired', 'cancelled']) => ['#fee2e2', '#991b1b'],
            in_array($s, ['refunded', 'partially_refunded', 'reversed']) => ['#e4e4e7', '#3f3f46'],
            default => ['#fef3c7', '#92400e'],
        };
    };
    [$tbg, $tfg] = $pill($t['status']);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Transaction Report #{{ $t['id'] }}</title>
    <style>
        @page { margin: 0 0 46px 0; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #18181b; line-height: 1.5; margin: 0; }
        .band { padding: 26px 40px 18px; border-bottom: 3px solid #1d4ed8; }
        .band .doc { color: #1d4ed8; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; }
        .band .meta { color: #64748b; font-size: 8.5px; text-align: right; line-height: 1.6; }
        .sheet { padding: 22px 40px 0; }
        .hero { background: #f4f6f5; border: 1px solid #dfe3e1; border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; }
        .hero .label { color: #3b82f6; font-size: 8px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 700; }
        .hero .amount { font-size: 21px; font-weight: 700; color: #0c1a2e; margin-top: 2px; }
        .hero .sub { color: #64748b; font-size: 8.5px; margin-top: 2px; }
        h2 { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.4px; color: #1d4ed8; margin: 20px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #e2e8f0; }
        .card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 4px 0; }
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 4.5px 14px; vertical-align: top; }
        table.kv tr + tr td { border-top: 1px solid #f1f5f9; }
        table.kv td.k { width: 30%; color: #64748b; }
        table.kv td.v { font-weight: 600; color: #18181b; }
        .pill { display: inline-block; border-radius: 8px; padding: 1.5px 7px; font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 8px; }
        .muted { color: #94a3b8; }
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
                    <div class="doc" style="margin-top: 7px;">Transaction Report</div>
                </td>
                <td class="meta">
                    <strong style="color:#0c1a2e; font-size:10.5px;">#{{ $t['id'] }}</strong><br>
                    Generated {{ $fmt($r['generated_at']) }}<br>
                    by {{ $r['generated_by'] }}
                </td>
            </tr>
        </table>
    </div>

    <div class="sheet">
        <div class="hero">
            <table style="width:100%; border-collapse:collapse;">
                <tr>
                    <td>
                        <div class="label">Amount</div>
                        <div class="amount">{{ $t['currency'] }} {{ number_format($t['amount'], 2) }}</div>
                        <div class="sub" style="text-transform:capitalize;">{{ $t['gateway'] }}{{ $t['payment_method'] ? ' · ' . str_replace('_', ' ', $t['payment_method']) : '' }} · {{ $t['context'] === 'order' ? 'order payment' : 'wallet funding' }}</div>
                    </td>
                    <td style="text-align:right; vertical-align:middle;">
                        <span class="pill" style="background: {{ $tbg }}; color: {{ $tfg }};">{{ $t['status'] }}</span>
                        <div class="sub" style="margin-top:5px;">{{ $t['confirmed_at'] ? 'Confirmed ' . $fmt($t['confirmed_at']) : ($t['failed_at'] ? 'Failed ' . $fmt($t['failed_at']) : 'Created ' . $fmt($t['created_at'])) }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <h2>Payment</h2>
        <div class="card">
            <table class="kv">
                <tr><td class="k">Gateway</td><td class="v" style="text-transform:capitalize;">{{ $t['gateway'] }}{{ $t['payment_method'] ? ' · ' . str_replace('_', ' ', $t['payment_method']) : '' }}</td></tr>
                {{-- tx_ref: OUR reference, the one sent to the gateway and shown
                     in its dashboard. Gateway reference: the PROVIDER's own id
                     for the same payment (flw_ref / payment id). --}}
                <tr><td class="k">Transaction ref (tx_ref)</td><td class="v mono">{{ $t['idempotency_key'] ?? $t['id'] }}</td></tr>
                <tr><td class="k">Gateway reference</td><td class="v mono">{{ $t['gateway_reference'] ?? '—' }}</td></tr>
                <tr><td class="k">Created</td><td class="v">{{ $fmt($t['created_at']) }}</td></tr>
                @if ($t['confirmed_at'])<tr><td class="k">Confirmed</td><td class="v">{{ $fmt($t['confirmed_at']) }}</td></tr>@endif
                @if ($t['failed_at'])<tr><td class="k">Failed</td><td class="v">{{ $fmt($t['failed_at']) }}</td></tr>@endif
            </table>
        </div>

        <h2>Settlement</h2>
        <div class="card">
            <table class="kv">
                <tr><td class="k">Amount</td><td class="v">{{ $t['currency'] }} {{ number_format($t['amount'], 2) }}</td></tr>
                @if ($t['exchange_rate'])<tr><td class="k">Exchange rate</td><td class="v">{{ $t['exchange_rate'] }}</td></tr>@endif
                @if ($t['gateway_fee'])<tr><td class="k">Gateway fee</td><td class="v">{{ $t['gateway_fee'] }}</td></tr>@endif
                @if ($t['settlement_delta'])<tr><td class="k">Settlement delta</td><td class="v">{{ $t['settlement_delta'] }}</td></tr>@endif
                @if ($t['pay_currency'])
                    <tr><td class="k">Crypto requested</td><td class="v">{{ strtoupper($t['pay_currency']) }} {{ $t['pay_amount'] }}{{ $t['network'] ? ' on ' . $t['network'] : '' }}</td></tr>
                    <tr><td class="k">Actually paid</td><td class="v">{{ $t['actually_paid'] ?? '—' }}</td></tr>
                    @if ($t['outcome_amount'])<tr><td class="k">Outcome</td><td class="v">{{ $t['outcome_amount'] }} {{ strtoupper((string) $t['outcome_currency']) }}</td></tr>@endif
                @endif
            </table>
        </div>

        <h2>Paid by</h2>
        @if (! empty($c['missing']))
            <div class="card"><div class="empty">No customer account is attached to this transaction.</div></div>
        @else
            <div class="card">
                <table class="kv">
                    <tr><td class="k">Customer</td><td class="v">{{ $c['name'] }}</td></tr>
                    <tr><td class="k">Email</td><td class="v">{{ $c['email'] }}</td></tr>
                </table>
            </div>
        @endif

        <h2>Paid for</h2>
        <div class="card">
            <table class="kv">
                @if ($o)
                    <tr><td class="k">Order</td><td class="v">{{ $o['number'] }} <span class="muted">(settlement {{ $usd($o['total_usd']) }} USD — see the order report for the full order story)</span></td></tr>
                @else
                    <tr><td class="k">Purpose</td><td class="v">Wallet funding</td></tr>
                @endif
            </table>
        </div>
    </div>

    <div class="footer">
        RshopRefill &middot; rshoprefill.com &middot; Generated {{ $fmt($r['generated_at']) }} &middot; Report {{ $r['id'] }}
    </div>
</body>
</html>
