{{--
    Personal data export (PDF): the copy of their data a customer can ask for
    under the NDPA right of access. Plain sections, one table per subject,
    branded like every other document we issue.

    DomPDF subset only: tables, no flexbox.

    @var array $data  DataExportService::build() output
--}}
@php
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('M j, Y H:i') . ' UTC' : '—';
    $show = fn ($v) => ($v === null || $v === '') ? '—' : (is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v);
    $a = $data['account'];
    $generated = \Illuminate\Support\Carbon::parse($data['generated_at']);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Your personal data</title>
    <style>
        @page { margin: 0 0 46px 0; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #18181b; line-height: 1.5; margin: 0; }
        .band { padding: 26px 40px 18px; border-bottom: 3px solid #1d4ed8; }
        .band .doc { color: #1d4ed8; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; }
        .band .meta { color: #64748b; font-size: 8.5px; text-align: right; line-height: 1.6; }
        .sheet { padding: 22px 40px 0; }
        .hero { background: #f4f6f5; border: 1px solid #dfe3e1; border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; }
        .hero .label { color: #3b82f6; font-size: 8px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 700; }
        .hero .title { font-size: 18px; font-weight: 700; color: #0c1a2e; margin-top: 2px; }
        .hero .sub { color: #64748b; font-size: 8.5px; margin-top: 4px; line-height: 1.6; }
        h2 { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.4px; color: #1d4ed8; margin: 20px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #e2e8f0; }
        .card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 4px 0; }
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 4.5px 14px; vertical-align: top; }
        table.kv tr + tr td { border-top: 1px solid #f1f5f9; }
        table.kv td.k { width: 32%; color: #64748b; }
        table.kv td.v { font-weight: 600; color: #18181b; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { text-align: left; color: #64748b; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.8px; padding: 6px 12px; border-bottom: 1px solid #e2e8f0; }
        table.list td { padding: 5px 12px; vertical-align: top; border-top: 1px solid #f1f5f9; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 8px; }
        .muted { color: #94a3b8; }
        .empty { color: #a1a1aa; font-style: italic; padding: 8px 12px; }
        .note { color: #64748b; font-size: 8px; margin-top: 10px; line-height: 1.6; }
        .footer { position: fixed; bottom: -34px; left: 0; right: 0; background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 9px 40px; color: #94a3b8; font-size: 7.5px; }
    </style>
</head>
<body>
    <div class="band">
        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <td>
                    {{-- JPEG deliberately: DomPDF embeds JPEG without the GD
                         extension. White-flattened for this light header. --}}
                    @if (is_file(public_path('assets/email-logo.jpg')))
                        <img src="{{ public_path('assets/email-logo.jpg') }}" style="height: 30px;" alt="RshopRefill">
                    @endif
                    <div class="doc" style="margin-top: 7px;">Personal data export</div>
                </td>
                <td class="meta">
                    Generated {{ $generated->format('M j, Y H:i') }} UTC<br>
                    rshoprefill.com
                </td>
            </tr>
        </table>
    </div>

    <div class="sheet">
        <div class="hero">
            <div class="label">Your data, in one place</div>
            <div class="title">{{ $show($a['name']) }}</div>
            <div class="sub">
                This document lists the personal data RshopRefill holds about you, as of the date above. You asked for it under your right of access.
                Keep it safe: it contains your contact details and your transaction history.
            </div>
        </div>

        <h2>Account</h2>
        <div class="card">
            <table class="kv">
                <tr><td class="k">Name</td><td class="v">{{ $show($a['name']) }}</td></tr>
                <tr><td class="k">Email</td><td class="v">{{ $show($a['email']) }} <span class="muted">{{ $a['email_verified_at'] ? '(verified ' . $fmt($a['email_verified_at']) . ')' : '(not verified)' }}</span></td></tr>
                <tr><td class="k">Phone</td><td class="v">{{ $show($a['phone']) }} <span class="muted">{{ $a['phone_verified_at'] ? '(verified)' : '' }}</span></td></tr>
                <tr><td class="k">Gender</td><td class="v">{{ $show($a['gender']) }}</td></tr>
                <tr><td class="k">Country</td><td class="v">{{ $show($a['country']) }}</td></tr>
                <tr><td class="k">Display currency</td><td class="v">{{ $show($a['display_currency']) }}</td></tr>
                <tr><td class="k">Signs in with Google</td><td class="v">{{ $show($a['signed_in_with_google']) }}</td></tr>
                <tr><td class="k">Identity verification</td><td class="v">{{ $show($a['kyc_status']) }}</td></tr>
                <tr><td class="k">Referral code</td><td class="v mono">{{ $show($a['referral_code']) }}</td></tr>
                <tr><td class="k">Account created</td><td class="v">{{ $fmt($a['created_at']) }}</td></tr>
                @if ($a['closure_requested_at'])
                    <tr><td class="k">Closure requested</td><td class="v">{{ $fmt($a['closure_requested_at']) }}</td></tr>
                @endif
            </table>
        </div>

        <h2>Communication preferences</h2>
        <div class="card">
            <table class="kv">
                @foreach ($data['communication_preferences'] as $label => $enabled)
                    <tr><td class="k">{{ $label }}</td><td class="v">{{ $enabled ? 'On' : 'Off' }}</td></tr>
                @endforeach
            </table>
        </div>

        <h2>Wallets</h2>
        <div class="card">
            @if ($data['wallets'] === [])
                <div class="empty">No wallets.</div>
            @else
                <table class="list">
                    <tr><th>Currency</th><th>Balance</th><th>Held</th><th>Opened</th></tr>
                    @foreach ($data['wallets'] as $w)
                        <tr><td>{{ $w['currency'] }}</td><td>{{ $w['balance'] }}</td><td>{{ $w['locked_balance'] }}</td><td>{{ $fmt($w['created_at']) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </div>

        <h2>Wallet funding</h2>
        <div class="card">
            @if ($data['wallet_fundings'] === [])
                <div class="empty">No wallet top-ups.</div>
            @else
                <table class="list">
                    <tr><th>Reference</th><th>Method</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                    @foreach ($data['wallet_fundings'] as $f)
                        <tr><td class="mono">{{ $f['reference'] }}</td><td>{{ $f['gateway'] }}</td><td>{{ $f['amount'] }} {{ $f['currency'] }}</td><td>{{ $f['status'] }}</td><td>{{ $fmt($f['created_at']) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </div>

        <h2>Wallet transactions</h2>
        <div class="card">
            @if ($data['wallet_transactions'] === [])
                <div class="empty">No wallet transactions.</div>
            @else
                <table class="list">
                    <tr><th>Date</th><th>Type</th><th>Amount</th><th>Description</th><th>Reference</th></tr>
                    @foreach ($data['wallet_transactions'] as $t)
                        <tr><td>{{ $fmt($t['created_at']) }}</td><td>{{ $t['type'] }}</td><td>{{ $t['amount'] }} {{ $t['currency'] }}</td><td>{{ $show($t['description']) }}</td><td class="mono">{{ $show($t['reference']) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </div>

        <h2>Orders</h2>
        <div class="card">
            @if ($data['orders'] === [])
                <div class="empty">No orders.</div>
            @else
                <table class="list">
                    <tr><th>Order</th><th>Placed</th><th>Items</th><th>Total</th><th>Status</th><th>Paid with</th></tr>
                    @foreach ($data['orders'] as $o)
                        <tr>
                            <td class="mono">{{ $o['order_number'] }}</td>
                            <td>{{ $fmt($o['placed_at']) }}</td>
                            <td>
                                @forelse ($o['items'] as $item)
                                    {{ $show($item['name']) }} × {{ $item['quantity'] }}<br>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td>{{ $o['total'] }} {{ $o['currency'] }}</td>
                            <td>{{ $o['status'] }} / {{ $o['payment_status'] }}</td>
                            <td>{{ $show($o['payment_method']) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <h2>Identity verification</h2>
        <div class="card">
            @if ($data['identity_verifications'] === [])
                <div class="empty">No identity checks.</div>
            @else
                <table class="list">
                    <tr><th>Provider</th><th>Result</th><th>Started</th><th>Decided</th></tr>
                    @foreach ($data['identity_verifications'] as $v)
                        <tr><td>{{ $v['provider'] }}</td><td>{{ $v['status'] }}</td><td>{{ $fmt($v['created_at']) }}</td><td>{{ $fmt($v['decided_at']) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </div>

        <h2>Trusted devices</h2>
        <div class="card">
            @if ($data['trusted_devices'] === [])
                <div class="empty">No trusted devices.</div>
            @else
                <table class="list">
                    <tr><th>Device</th><th>Browser</th><th>System</th><th>Last seen</th></tr>
                    @foreach ($data['trusted_devices'] as $d)
                        <tr><td>{{ $show($d['device_name']) }}</td><td>{{ $show($d['browser']) }}</td><td>{{ $show($d['os']) }}</td><td>{{ $fmt($d['last_seen_at']) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </div>

    </div>

    <div class="footer">
        Roddy Technologies LTD · rshoprefill.com · Personal data export for {{ $show($a['name']) }} · Generated {{ $generated->format('M j, Y H:i') }} UTC
    </div>
</body>
</html>
