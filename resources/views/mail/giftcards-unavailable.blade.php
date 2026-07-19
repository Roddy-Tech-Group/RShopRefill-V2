<x-emails.layout :mail-message="$message ?? null" title="Gift cards temporarily unavailable" preheader="Our gift cards service is under brief maintenance.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Gift cards temporarily unavailable</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hello {{ $user->name }},</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">We wanted to let you know that our gift cards service is currently undergoing temporary maintenance to improve reliability and ensure you have the best possible experience.</p>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">During this time, you will not be able to purchase or trade new gift cards. Please rest assured that your wallet balances, account details, and any previous orders remain perfectly safe and unaffected.</p>

    <p style="margin:0; font-size:16px; line-height:1.65; color:#3f3f46;">We are working hard on these improvements and the service will be back as soon as possible. We apologize for any inconvenience and appreciate your patience.</p>
</x-emails.layout>
