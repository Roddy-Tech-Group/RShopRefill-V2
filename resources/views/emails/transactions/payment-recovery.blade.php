<x-emails.layout :mail-message="$message ?? null" :title="$headline" :preheader="$guidance">
    <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:800; color:#0c1a2e;">{{ $headline }}</h1>

    <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Hi {{ $name }},</p>

    @if ($isReminder)
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">Just a quick nudge, your {{ $noun }} is still waiting.</p>
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
                    @elseif ($isFunding)
                        <p style="margin:0 0 6px; font-size:13px; line-height:1.5; color:#71717a;">Wallet top-up</p>
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

    @if ($uncertain)
        {{-- No "complete your payment" here: the money may already have moved. --}}
        <p style="margin:0 0 16px; font-size:16px; line-height:1.65; color:#3f3f46;">You do not need to do anything now. If you want to check on it, reply to this email and we will look it up for you.</p>
    @else
        <x-emails.button :url="$resumeUrl" align="center">{{ $isFunding ? 'Complete your top-up' : 'Complete your payment' }}</x-emails.button>

        <p style="margin:16px 0 0; font-size:14px; line-height:1.65; color:#71717a;">
            {{ $expiryLine }} If you have already paid, nothing more is needed and you can ignore this email.
        </p>
    @endif

    <p style="margin:22px 0 0; font-size:12px; line-height:1.7; color:#a1a1aa;">
        You are receiving this because a payment on your account did not complete.
        <a href="{{ $manageUrl }}" style="color:#71717a;">Manage payment emails</a>@if ($unsubscribeUrl) or <a href="{{ $unsubscribeUrl }}" style="color:#71717a;">unsubscribe</a>@endif.
    </p>
</x-emails.layout>
