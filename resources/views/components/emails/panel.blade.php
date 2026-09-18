@props([
    'title' => null,
    'background' => '#f4f7ff',
    'border' => '#dfe3e1',
    'accent' => '#2563eb',
])
{{-- Highlight box for transactional detail (order info, balances, reasons).
     Put <x-emails.row> children or free markup inside. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0; background:{{ $background }}; border:1px solid {{ $border }}; border-radius:12px;">
    <tr>
        <td style="padding:20px 24px; font-family:'Inter',-apple-system,Helvetica,Arial,sans-serif;">
            @if ($title)
                <p style="margin:0 0 14px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.07em; color:{{ $accent }};">{{ $title }}</p>
            @endif
            {{ $slot }}
        </td>
    </tr>
</table>
