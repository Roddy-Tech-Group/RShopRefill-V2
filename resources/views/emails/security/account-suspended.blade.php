<x-emails.layout :mail-message="$message ?? null" title="Your account is under review" preheader="Purchases and funding on your {{ $siteName }} account are paused while our team reviews it.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Your account is under review</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, our team has placed your {{ $siteName }} account under review. You can still sign in, see your orders and reach support, but purchases, wallet funding and checkout are paused until the review is complete.</p>

    @if($reason)
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;"><strong>Reason given:</strong> {{ $reason }}</p>
    @endif

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Your balance and order history are kept safe and are not affected.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">To ask for a review, sign in and use the request-a-review option on your dashboard, or write to <a href="mailto:{{ $supportEmail }}" style="color:#2563eb;">{{ $supportEmail }}</a> from this email address.</p>
</x-emails.layout>
