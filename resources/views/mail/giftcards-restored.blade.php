<x-mail::message>
# Great News! Gift Cards Are Back!

Hello {{ $user->name }},

Our Gift Card service is now fully restored and ready to use. Thank you for your patience and understanding while we worked to get everything back online.

We appreciate your continued trust in RShopRefills. Happy shopping!

<x-mail::button :url="config('app.url') . '/dashboard/gift-cards'">
Trade Gift Cards Now
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
