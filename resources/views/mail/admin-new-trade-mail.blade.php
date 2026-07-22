<x-emails.layout :mail-message="$message ?? null" title="New Trade Submitted" preheader="A user has just submitted a new gift card trade.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">New Trade Submitted</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">A user has just submitted a new gift card trade.</p>

    <x-emails.panel title="Trade Details">
        <p style="margin:0 0 8px; font-size:14px; line-height:1.55; color:#3f3f46;"><strong>User:</strong> {{ $trade->user->name }} ({{ $trade->user->email }})</p>
        <p style="margin:0 0 8px; font-size:14px; line-height:1.55; color:#3f3f46;"><strong>Card Brand:</strong> {{ $trade->rate->brand->name ?? 'Unknown' }}</p>
        <p style="margin:0 0 8px; font-size:14px; line-height:1.55; color:#3f3f46;"><strong>Declared Value:</strong> ${{ number_format($trade->declared_value, 2) }}</p>
        <p style="margin:0; font-size:14px; line-height:1.55; color:#3f3f46;"><strong>Expected Payout:</strong> {{ \App\Domain\Shared\Enums\Currency::tryFrom($trade->payout_currency)?->symbol() ?? $trade->payout_currency . ' ' }}{{ number_format($trade->calculated_payout, 2) }}</p>
    </x-emails.panel>

    <x-emails.button :url="url('/admin/gift-cards/trades/'.$trade->id)" align="center">Review Trade Now</x-emails.button>

    <p style="margin:16px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">Please review this trade as soon as possible to ensure a fast payout experience for the user.</p>
</x-emails.layout>
