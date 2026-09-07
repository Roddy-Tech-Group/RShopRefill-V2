<x-emails.layout :mail-message="$message ?? null" title="A device was signed out of your account" preheader="{{ $deviceLabel }} was signed out of your {{ $siteName }} account and removed from your trusted devices.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">A device was signed out of your account</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, <strong>{{ $deviceLabel }}</strong>@if($ipAddress) (last seen from {{ $ipAddress }})@endif was just signed out of your {{ $siteName }} account and removed from your trusted devices. It will need to sign in and verify again before it can be used.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If this was you, there is nothing to do.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If it was not, change your password now from the Security Center, then reply to this email or write to <a href="mailto:{{ $supportEmail }}" style="color:#2563eb;">{{ $supportEmail }}</a> so we can secure the account.</p>
</x-emails.layout>
