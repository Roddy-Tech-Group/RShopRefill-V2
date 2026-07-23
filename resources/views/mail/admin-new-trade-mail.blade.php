@php
    $payoutSymbol = \App\Domain\Shared\Enums\Currency::tryFrom($trade->payout_currency)?->symbol() ?? $trade->payout_currency.' ';
@endphp
<x-emails.layout :mail-message="$message ?? null" title="New trade submitted" preheader="A user has submitted a new gift card trade for review.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">New gift card trade submitted</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">A user has just submitted a new gift card trade for review.</p>

    <x-emails.panel title="Trade details">
        <x-emails.row label="User" value="{{ $trade->user->name }}" />
        <x-emails.row label="Email" value="{{ $trade->user->email }}" />
        <x-emails.row label="Card brand" value="{{ $trade->rate->brand->name ?? 'Unknown' }}" />
        <x-emails.row label="Declared value" value="${{ number_format($trade->declared_value, 2) }}" />
        <x-emails.row label="Expected payout" value="{{ $payoutSymbol }}{{ number_format($trade->calculated_payout, 2) }}" />
    </x-emails.panel>

    <x-emails.button :url="url('/admin/gift-cards/trades/'.$trade->id)" align="center">Review trade now</x-emails.button>

    <p style="margin:0; font-size:16px; line-height:1.65; color:#3f3f46;">Please review this trade as soon as possible to ensure a fast payout for the user.</p>
</x-emails.layout>
