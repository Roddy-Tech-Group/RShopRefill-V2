<x-emails.layout :mail-message="$message ?? null" title="Account Password Changed" preheader="Your account password has been changed.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Account Password Changed</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $user->name }},</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">This is a confirmation that your account password has been successfully changed.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If you did not make this change, please contact support immediately to secure your account.</p>
</x-emails.layout>
