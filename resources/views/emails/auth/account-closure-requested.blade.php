<x-emails.layout :mail-message="$message ?? null" title="Your account will close on {{ $closesOn }}" preheader="Sign in before {{ $closesOn }} if you want to keep your {{ $siteName }} account.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Your account will close on {{ $closesOn }}</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, we received your request to close your {{ $siteName }} account. You have been signed out, and nothing has been removed yet.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Changed your mind? Simply sign in again before {{ $closesOn }} and your account stays open, exactly as you left it.</p>

    <x-emails.button :url="$loginUrl" align="center">Keep my account</x-emails.button>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#71717a;">If you do nothing, your account will be permanently closed after {{ $graceDays }} days.</p>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">If you did not ask to close your account, sign in now to cancel the closure and change your password.</p>
</x-emails.layout>
