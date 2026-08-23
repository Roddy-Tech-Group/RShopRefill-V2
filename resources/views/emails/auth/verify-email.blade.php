<x-emails.layout :mail-message="$message ?? null" title="Verify your email" preheader="Confirm your {{ $siteName }} email address to activate your account. This link expires in {{ $expireMinutes }} minutes.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Verify your email address</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, welcome to {{ $siteName }}. Confirm this is your email address to activate your account and start ordering. Tap the button below.</p>

    <x-emails.button :url="$verifyUrl" align="center">Verify email address</x-emails.button>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#71717a;">This link expires in {{ $expireMinutes }} minutes. If the button does not work, copy and paste this URL into your browser:</p>
    <p style="margin:6px 0 0; font-size:13px; line-height:1.6; word-break:break-all;"><a href="{{ $verifyUrl }}" style="color:#2563eb;">{{ $verifyUrl }}</a></p>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">If you did not create a {{ $siteName }} account, you can safely ignore this email.</p>
</x-emails.layout>
