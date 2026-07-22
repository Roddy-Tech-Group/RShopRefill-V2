<x-emails.layout :mail-message="$message ?? null" title="Security Alert: Transaction PIN Locked" preheader="Your wallet transaction PIN has been locked.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Security Alert: PIN Locked</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $user->name }},</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">We noticed multiple failed attempts to verify your wallet transaction PIN. For your security, we have temporarily locked your transaction PIN for 15 minutes.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If this was you, you can try again after 15 minutes.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If you did not make these attempts, please reset your PIN immediately or contact support to secure your account.</p>

    <x-emails.button :url="config('app.frontend_url') . '/wallet/pin/reset'" align="center">Reset My PIN</x-emails.button>
</x-emails.layout>
