<x-emails.layout :mail-message="$message ?? null" title="Gift Card Trade Update" preheader="The status of your gift card trade has been updated.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Trade Update: {{ $trade->status->label() }}</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hello {{ $trade->user->name }},</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">The status of your gift card trade <strong>#{{ substr($trade->uuid, 0, 8) }}</strong> has been updated to: <strong>{{ $trade->status->label() }}</strong>.</p>

    @if($trade->status === \App\Enums\TradeStatus::Approved)
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Your gift card has been verified successfully. We are now processing your payout to your selected payment method!</p>
    @endif

    @if($reason)
        <x-emails.panel title="Admin Note">
            <p style="margin:0; font-size:14px; line-height:1.55; color:#3f3f46;">{{ $reason }}</p>
        </x-emails.panel>
    @endif

    <x-emails.button :url="url('/dashboard/gift-cards/trades/'.$trade->id)" align="center">View Trade Details</x-emails.button>
</x-emails.layout>
