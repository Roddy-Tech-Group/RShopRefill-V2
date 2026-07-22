<x-emails.layout :mail-message="$message ?? null" title="Transaction PIN Reset" preheader="We received a request to reset your wallet PIN.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Transaction PIN Reset Request</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $user->name }},</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">We received a request to reset your wallet transaction PIN.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Click the button below to set a new PIN. This link will expire in 1 hour.</p>

    <x-emails.button :url="route('dashboard.security.pin-reset', ['token' => $token])" align="center">Reset PIN</x-emails.button>

    <p style="margin:16px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">If you did not request a PIN reset, please ignore this email or contact support if you have concerns.</p>
</x-emails.layout>
