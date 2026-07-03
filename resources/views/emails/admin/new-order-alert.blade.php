<x-emails.layout :mail-message="$message ?? null" title="New order alert" preheader="A new order was placed on {{ $siteName }}.">
    @if ($isLargeTransaction)
        <span style="display:inline-block; margin:0 0 12px; padding:6px 12px; background:#fee2e2; color:#b91c1c; border-radius:6px; font-size:12px; font-weight:700;">Large transaction</span>
        <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#b91c1c;">A large order was placed.</h1>
    @else
        <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">A new order was placed.</h1>
    @endif

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">A new order has been recorded on the platform. Review the details below.</p>

    <x-emails.panel title="Order">
        <x-emails.row label="Order number" value="#{{ $orderNumber }}" />
        <x-emails.row label="Customer" value="{{ $customerName }}" />
        <x-emails.row label="Email" value="{{ $customerEmail }}" :strong="false" />
        <x-emails.row label="Total" value="{{ $currency }} {{ number_format($totalAmount, 2) }}" />
    </x-emails.panel>

    <x-emails.button :url="url('/admin/orders')" align="center">Open in admin</x-emails.button>
</x-emails.layout>
