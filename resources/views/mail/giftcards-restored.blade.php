<x-emails.layout :mail-message="$message ?? null" title="Gift cards are back" preheader="Our gift card service is fully restored and ready to use.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Great news, gift cards are back</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hello {{ $user->name }},</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Our gift card service is now fully restored and ready to use. Thank you for your patience and understanding while we worked to get everything back online.</p>

    <x-emails.button :url="url('/dashboard/shop/gift-cards')" align="center">Shop gift cards now</x-emails.button>
</x-emails.layout>
