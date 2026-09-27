<x-emails.layout :mail-message="$message ?? null" title="Your Authentication Code" preheader="Your one-time verification code for RshopRefill.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Verification Code</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">
        @if($context === 'recovery')
            You are attempting to recover your Protected Mode account.
        @elseif($context === 'data_export')
            You asked to download a copy of the personal data we hold about you. Enter this code to confirm it is you.
        @else
            You are attempting to sign in from a new or unrecognized device.
        @endif
    </p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Here is your one-time verification code:</p>

    <div style="margin:0 0 24px; text-align:center;">
        <span style="display:inline-block; padding:16px 32px; font-size:32px; font-weight:800; letter-spacing:8px; color:#0c1a2e; background:#f4f4f5; border-radius:12px; font-family:monospace;">{{ $otp }}</span>
    </div>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">This code will expire in 10 minutes. Do not share this code with anyone.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If you did not initiate this request, please ignore this email or contact support.</p>
</x-emails.layout>
