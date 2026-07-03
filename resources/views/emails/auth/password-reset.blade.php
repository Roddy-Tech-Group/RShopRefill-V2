<x-emails.layout :mail-message="$message ?? null" title="Reset your password" preheader="Reset your {{ $siteName }} password. This link expires in 60 minutes.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Reset your password</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, we received a request to reset the password for your {{ $siteName }} account. Tap the button below to choose a new one.</p>

    <x-emails.button :url="$resetUrl" align="center">Reset password</x-emails.button>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#71717a;">This link expires in 60 minutes. If the button does not work, copy and paste this URL into your browser:</p>
    <p style="margin:6px 0 0; font-size:13px; line-height:1.6; word-break:break-all;"><a href="{{ $resetUrl }}" style="color:#2563eb;">{{ $resetUrl }}</a></p>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">If you did not request a password reset, you can safely ignore this email. Your password will stay the same.</p>
</x-emails.layout>
