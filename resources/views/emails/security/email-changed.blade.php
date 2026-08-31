<x-emails.layout :mail-message="$message ?? null" title="Your account email was changed" preheader="The email address on your {{ $siteName }} account was changed to {{ $newEmailMasked }}.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Your account email was changed</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, the email address on your {{ $siteName }} account was just changed to <strong>{{ $newEmailMasked }}</strong>. This address will no longer receive messages about the account.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If this was you, there is nothing to do.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If it was not, reply to this email or write to <a href="mailto:{{ $supportEmail }}" style="color:#2563eb;">{{ $supportEmail }}</a> straight away so we can secure the account.</p>
</x-emails.layout>
