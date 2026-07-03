<x-emails.layout :mail-message="$message ?? null" title="Welcome to {{ $siteName }}" preheader="Your account is ready. Explore gift cards, eSIMs, top-ups and bills.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Welcome aboard, {{ $name }}.</h1>

    @if ($isGoogleAuth)
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Your account is ready, securely linked through Google Sign-In. You are all set to start shopping digital products in seconds.</p>
    @else
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Thanks for joining {{ $siteName }}. Your account is ready, so you can start buying gift cards, eSIMs, mobile top-ups and bill payments in seconds.</p>
    @endif

    <x-emails.panel title="What you can do">
        <p style="margin:0 0 8px; font-size:14px; line-height:1.55; color:#3f3f46;">Browse the catalogue and pay your way: card, mobile money, crypto or wallet.</p>
        <p style="margin:0 0 8px; font-size:14px; line-height:1.55; color:#3f3f46;">Fund your wallet once, then check out instantly next time.</p>
        <p style="margin:0; font-size:14px; line-height:1.55; color:#3f3f46;">Earn rewards on every order you place.</p>
    </x-emails.panel>

    <x-emails.button :url="url('/dashboard')" align="center">Go to your dashboard</x-emails.button>

    <p style="margin:18px 0 0; font-size:13px; line-height:1.6; color:#a1a1aa;">You are receiving this email because an account was created with {{ $email }}.</p>
</x-emails.layout>
