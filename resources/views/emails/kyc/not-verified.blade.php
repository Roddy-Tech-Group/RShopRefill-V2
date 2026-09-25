<x-emails.layout :mail-message="$message ?? null" title="We could not verify your identity" preheader="We could not verify your identity. You can try again.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">We could not verify your identity</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, we could not verify your identity.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Most attempts fail for simple reasons. Before you try again, check that:</p>

    <ul style="margin:0 0 16px; padding-left:20px; font-size:15px; line-height:1.7; color:#3f3f46;">
        <li>your ID is valid and not expired,</li>
        <li>the whole document is in the frame and easy to read,</li>
        <li>your face is well lit and clearly visible in the selfie.</li>
    </ul>

    <x-emails.button :url="route('dashboard.kyc')" align="center">Try again</x-emails.button>

    <p style="margin:0; font-size:15px; line-height:1.6; font-weight:700; color:#0c1a2e;">The RshopRefill Team</p>
</x-emails.layout>
