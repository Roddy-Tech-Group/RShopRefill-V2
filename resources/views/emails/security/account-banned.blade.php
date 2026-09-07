<x-emails.layout :mail-message="$message ?? null" title="Your account has been suspended" preheader="Your {{ $siteName }} account has been suspended and signed out on every device.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Your account has been suspended</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, your {{ $siteName }} account has been suspended following a review by our team, and it has been signed out on every device. You will not be able to sign in while the suspension stands.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Any balance and order history on the account are kept safe and are not affected by this.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If you believe this is a mistake, or you would like to understand the decision, write to <a href="mailto:{{ $supportEmail }}" style="color:#2563eb;">{{ $supportEmail }}</a> from this email address and our team will look into it.</p>
</x-emails.layout>
