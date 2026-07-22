<x-emails.layout :mail-message="$message ?? null" title="Admin 2FA Code" preheader="Your admin login code is {{ $otp }}.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Hello {{ $admin->name }},</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Your admin login code is:</p>

    <div style="text-align: center; font-size: 28px; font-weight: bold; letter-spacing: 6px; padding: 20px; background-color: #f4f4f5; border-radius: 8px; margin-bottom: 24px; color: #18181b;">
        {{ $otp }}
    </div>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">This code will expire in 10 minutes.</p>

    <p style="margin:16px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">If you did not attempt to log in to the admin panel, please ignore this email or contact another administrator.</p>
</x-emails.layout>
