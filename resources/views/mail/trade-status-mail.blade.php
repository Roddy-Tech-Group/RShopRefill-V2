<x-emails.layout :mail-message="$message ?? null" title="Trade status update" preheader="Your gift card trade status has been updated.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Hello {{ $trade->user->name }},</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">
        The status of your gift card trade <strong>#{{ substr($trade->uuid, 0, 8) }}</strong> has been updated to <strong style="color:#2563eb;">{{ $trade->status->label() }}</strong>.
    </p>

    @if ($trade->status === \App\Enums\TradeStatus::Approved)
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">
            Your gift card has been verified successfully. We are now processing your payout to your selected payment method.
        </p>
    @endif

    @if ($reason)
        <x-emails.panel title="Admin note">
            <p style="margin:0; font-size:14px; line-height:1.6; color:#3f3f46;">{{ $reason }}</p>
        </x-emails.panel>
    @endif

    <x-emails.button :url="url('/dashboard/gift-cards/trades/'.$trade->id)" align="center">View trade details</x-emails.button>

    <p style="margin:0; font-size:16px; line-height:1.65; color:#3f3f46;">Thank you for trading with us.</p>
</x-emails.layout>
