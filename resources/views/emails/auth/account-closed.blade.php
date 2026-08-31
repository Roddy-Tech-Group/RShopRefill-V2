<x-emails.layout :mail-message="$message ?? null" title="Your account has been closed" preheader="Your {{ $siteName }} account is now closed.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Your account has been closed</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, the grace period on your closure request has ended and your {{ $siteName }} account is now closed.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">You are welcome back any time. We would love to have you again, and a new account is only a minute away.</p>
</x-emails.layout>
