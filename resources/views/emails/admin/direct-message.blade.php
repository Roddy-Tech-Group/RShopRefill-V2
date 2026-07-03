@php
    $isWarning = $type === 'warning';
    $headline = $isWarning ? 'Important update about your account' : 'A message from '.$siteName;
    $preheader = $isWarning
        ? 'Please review this warning about your '.$siteName.' account.'
        : 'You have a new message from the '.$siteName.' team.';
    $accent = $isWarning ? '#b45309' : '#2563eb';   // amber-700 vs brand blue
    $bgTint = $isWarning ? '#fef3c7' : '#dbeafe';   // amber-100 vs blue-100
    $badge  = $isWarning ? 'Warning' : 'Notification';
@endphp
<x-emails.layout :mail-message="$message ?? null" :title="$headline" :preheader="$preheader">
    <p style="margin:0 0 12px;">
        <span style="display:inline-block; padding:4px 10px; border-radius:5px; background:{{ $bgTint }}; color:{{ $accent }}; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase;">{{ $badge }}</span>
    </p>

    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">{{ $headline }}</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }},</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46; white-space:pre-line;">{{ $body }}</p>

    @if ($isWarning)
        <p style="margin:18px 0 0; font-size:14px; line-height:1.65; color:#6b7280;">If you believe this warning was sent in error, please reply to this email or contact support so we can review.</p>
    @endif

    <x-emails.button :url="url('/dashboard')" align="center">Open your dashboard</x-emails.button>
</x-emails.layout>
