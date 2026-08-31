<x-emails.layout :mail-message="$message ?? null" title="Your data export is attached" preheader="The copy of your {{ $siteName }} data you asked for is attached as a PDF.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Your data export is attached</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, following your request, a copy of the personal data {{ $siteName }} holds about you is attached to this email as a PDF.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Keep it safe: it contains your contact details and your transaction history.</p>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">If you did not ask for this, contact support straight away.</p>
</x-emails.layout>
