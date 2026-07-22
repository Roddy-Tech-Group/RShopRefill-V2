@php
$lockUrl = route('security.compromise', [
    'device' => $device->id,
    'token' => hash_hmac('sha256', $device->id, config('app.key'))
]);
@endphp

<x-emails.layout :mail-message="$message ?? null" title="New device signed into your account" preheader="We noticed a new sign-in to your RShopRefill account.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">New Device Detected</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $device->user->name }},</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">We noticed a new sign-in to your RShopRefill account.</p>

    <div style="margin:0 0 24px; padding:16px; background:#f4f4f5; border-radius:12px;">
        <ul style="margin:0; padding:0; list-style:none; font-size:15px; color:#3f3f46; line-height:1.6;">
            <li><strong>Device:</strong> {{ $device->os }} / {{ $device->browser }}</li>
            <li><strong>IP Address:</strong> {{ $device->ip_address }}</li>
            <li><strong>Location:</strong> {{ $device->city ?: 'Unknown City' }}, {{ $device->country ?: 'Unknown Country' }}</li>
            <li><strong>Time:</strong> {{ now()->toDayDateTimeString() }}</li>
        </ul>
    </div>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If this was you, you can safely ignore this email.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">If you did not sign in recently, please secure your account immediately.</p>

    <div style="margin:32px 0; text-align:center;">
        <a href="{{ $lockUrl }}" style="display:inline-block; padding:14px 28px; background-color:#ef4444; color:#ffffff; font-size:16px; font-weight:600; text-decoration:none; border-radius:8px;">NO, SECURE MY ACCOUNT</a>
    </div>

    <p style="margin:0 0 16px; font-size:14px; line-height:1.65; color:#71717a;">Clicking the secure link will instantly log out all devices and freeze financial operations on your account.</p>
</x-emails.layout>
