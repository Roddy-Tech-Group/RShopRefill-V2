<x-emails.layout :mail-message="$message ?? null" :title="$headline" :preheader="$guidance">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">{{ $headline }}</h1>

    @if ($isReminder)
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Just a quick nudge, your order is still saved and waiting.</p>
    @endif

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">{{ $guidance }}</p>

    @if ($hint)
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">{{ $hint }}</p>
    @endif

    {{-- What is actually waiting for them. A customer decides whether to
         bother based on the contents, not on the fact that something failed. --}}
    @if ($orderNumber || $amount)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 20px; background:#f7f8fa; border-radius:12px;">
            <tr>
                <td style="padding:16px 18px;">
                    @if ($orderNumber)
                        <p style="margin:0 0 6px; font-size:13px; line-height:1.5; color:#71717a;">Order {{ $orderNumber }}</p>
                    @endif
                    @if (count($itemNames))
                        <p style="margin:0 0 6px; font-size:15px; line-height:1.5; font-weight:600; color:#0c1a2e;">
                            {{ implode(', ', $itemNames) }}@if ($itemCount > count($itemNames)) and {{ $itemCount - count($itemNames) }} more @endif
                        </p>
                    @endif
                    @if ($amount)
                        <p style="margin:0; font-size:18px; line-height:1.4; font-weight:800; color:#0c1a2e;">
                            @moneyCode((float) $amount, $currency ?? 'USD')
                        </p>
                    @endif
                </td>
            </tr>
        </table>
    @endif

    <x-emails.button :url="$resumeUrl" align="center">Complete your payment</x-emails.button>

    <p style="margin:16px 0 0; font-size:14px; line-height:1.65; color:#71717a;">
        This link works until {{ $expiresAt?->format('D j M, g:ia') }}. If you have already paid, nothing more is needed and you can ignore this email.
    </p>
</x-emails.layout>
