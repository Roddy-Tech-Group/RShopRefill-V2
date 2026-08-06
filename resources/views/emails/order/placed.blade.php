<x-emails.layout :mail-message="$message ?? null" title="Order confirmed" preheader="We received your payment. Here is your confirmation.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Thanks, your order is confirmed.</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, we have received your payment and are getting your digital products ready. You will get another email the moment they are delivered.</p>

    <x-emails.panel title="Order summary">
        <x-emails.row label="Order number" value="#{{ $orderNumber }}" />
        <x-emails.row label="Items" value="{{ $itemsCount }}" />
        <x-emails.row label="Total paid" value="{{ $currency }} {{ number_format($total, 2) }}" />
    </x-emails.panel>

    <x-emails.button :url="url('/dashboard/orders')" align="center">View your orders</x-emails.button>
</x-emails.layout>
