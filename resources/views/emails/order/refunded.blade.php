<x-emails.layout :mail-message="$message ?? null" title="Refund processed" preheader="A refund has been processed back to your wallet.">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">Your refund has been processed.</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }}, we have refunded your order to your {{ $siteName }} wallet. The balance is available to use right away.</p>

    <x-emails.panel title="Refund details">
        <x-emails.row label="Order number" value="#{{ $orderNumber }}" />
        <x-emails.row label="Amount refunded" value="{{ $currency }} {{ number_format($amount, 2) }}" />
        <x-emails.row label="Reason" value="{{ $reason }}" :strong="false" />
    </x-emails.panel>

    <x-emails.button :url="url('/dashboard/wallet')" align="center">View your wallet</x-emails.button>
</x-emails.layout>
