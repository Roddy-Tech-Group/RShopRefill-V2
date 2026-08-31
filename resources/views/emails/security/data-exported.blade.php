<x-emails.layout :mail-message="$message ?? null" title="A copy of your data was downloaded" preheader="A copy of your {{ $siteName }} data was downloaded on {{ $when }}.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">A copy of your data was downloaded</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, a copy of the personal data we hold about you was downloaded from your {{ $siteName }} account.</p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px; width:100%; border-collapse:collapse;">
        <tr><td style="padding:6px 0; font-size:14px; color:#71717a; width:90px;">When</td><td style="padding:6px 0; font-size:14px; font-weight:600; color:#0c1a2e;">{{ $when }}</td></tr>
        <tr><td style="padding:6px 0; font-size:14px; color:#71717a;">Device</td><td style="padding:6px 0; font-size:14px; font-weight:600; color:#0c1a2e;">{{ $device }}</td></tr>
    </table>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If this was you, there is nothing to do.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If it was not, change your password now and review your trusted devices.</p>

    <x-emails.button :url="$securityUrl" align="center">Review security settings</x-emails.button>
</x-emails.layout>
