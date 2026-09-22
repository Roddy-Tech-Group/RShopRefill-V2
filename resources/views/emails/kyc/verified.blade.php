@php
    $perks = [
        ['emoji' => '🎁', 'label' => 'Gift cards', 'url' => route('dashboard.shop.gift-cards')],
        ['emoji' => '📶', 'label' => 'eSIMs', 'url' => route('dashboard.shop.esims')],
        ['emoji' => '📱', 'label' => 'Top-ups', 'url' => route('dashboard.shop.topups')],
    ];
@endphp
<x-emails.layout :mail-message="$message ?? null" title="You're verified!" preheader="Congratulations, your RshopRefill account is now verified. Time to shop!">
    {{-- Verified badge: a green disc with a white check. Plain text glyph,
         not an SVG or emoji, so every mail client draws it the same. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 18px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="center" valign="middle" width="84" height="84" style="width:84px; height:84px; background:#16a34a; border:6px solid #dcfce7; border-radius:48px; font-family:Arial,Helvetica,sans-serif; font-size:44px; font-weight:700; line-height:84px; color:#ffffff; text-align:center;">&#10003;</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <h1 style="margin:0 0 10px; font-size:26px; line-height:1.25; font-weight:800; color:#0c1a2e; text-align:center;">You're verified!</h1>

    <p style="margin:0 0 6px; font-size:17px; line-height:1.6; color:#3f3f46; text-align:center;">Hi {{ $name }}, congratulations! 🎉</p>

    <p style="margin:0 0 22px; font-size:16px; line-height:1.65; color:#3f3f46; text-align:center;">Your RshopRefill account is now verified. Enjoy seamless shopping, from gift cards to eSIMs and top-ups, all in a few taps.</p>

    {{-- Quick links into the store --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 4px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        @foreach ($perks as $perk)
                            <td style="padding:0 5px;">
                                <a href="{{ $perk['url'] }}" style="display:inline-block; background:#f4f7ff; border:1px solid #dbe4ff; border-radius:999px; padding:9px 14px; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif; font-size:13px; font-weight:600; color:#1e3a8a; text-decoration:none; white-space:nowrap;">{{ $perk['emoji'] }} {{ $perk['label'] }}</a>
                            </td>
                        @endforeach
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <x-emails.button :url="route('home')" align="center">Shop now</x-emails.button>

    <p style="margin:0; font-size:15px; line-height:1.6; color:#52525b; text-align:center;">Happy shopping! 🛍️</p>
    <p style="margin:4px 0 0; font-size:15px; line-height:1.6; font-weight:700; color:#0c1a2e; text-align:center;">The RshopRefill Team</p>
</x-emails.layout>
