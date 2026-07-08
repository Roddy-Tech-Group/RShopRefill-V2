<x-emails.layout :mail-message="$message ?? null" title="Claim your account" preheader="Set a password to track your order, manage your purchases and use your wallet.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Your order is in. Claim your account.</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, thanks for your purchase. We created an account for you with this email address so you can follow up on your orders, manage the products and services you bought, and use your wallet.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Tap the button below to set your password and a transaction PIN. Everything you have already ordered will be waiting in your account.</p>

    <x-emails.button :url="$claimUrl" align="center">Claim my account</x-emails.button>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#71717a;">This link expires in 7 days. If the button does not work, copy and paste this URL into your browser:</p>
    <p style="margin:6px 0 0; font-size:13px; line-height:1.6; word-break:break-all;"><a href="{{ $claimUrl }}" style="color:#2563eb;">{{ $claimUrl }}</a></p>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">If you did not place this order, you can safely ignore this email.</p>
</x-emails.layout>
