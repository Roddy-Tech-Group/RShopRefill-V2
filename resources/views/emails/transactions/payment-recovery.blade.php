<x-emails.layout :mail-message="$message ?? null" title="Complete your payment" preheader="Your recent payment attempt was not completed. You can securely resume it.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Complete your payment.</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hello, we noticed your recent payment attempt was not completed.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">You can securely resume your payment or tell us what went wrong by clicking the button below.</p>

    <x-emails.button :url="$resumeUrl" align="center">Continue Payment</x-emails.button>

    <p style="margin:16px 0 0; font-size:14px; line-height:1.65; color:#71717a;">This link will expire in 24 hours. If you have already completed this payment, you can safely ignore this email.</p>
</x-emails.layout>
