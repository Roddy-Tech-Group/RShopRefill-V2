{{-- No codes, PINs or activation details, ever: this email is blind-copied
     to the review platform. It only points at where the codes already are. --}}
<x-emails.layout :mail-message="$message ?? null" title="Order complete" preheader="Everything on your order has been delivered.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Your order is complete.</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, everything on your order has been delivered. You can see the details any time on your order page.</p>

    <x-emails.panel title="Order summary">
        <x-emails.row label="Order number" value="#{{ $orderNumber }}" />
        <x-emails.row label="Items" value="{{ $itemsCount }}" />
        <x-emails.row label="Total paid" value="{{ $currency }} {{ number_format($total, 2) }}" />
    </x-emails.panel>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Thank you for shopping with us.</p>

    <x-emails.button :url="url('/dashboard/orders')" align="center">View your orders</x-emails.button>
</x-emails.layout>
