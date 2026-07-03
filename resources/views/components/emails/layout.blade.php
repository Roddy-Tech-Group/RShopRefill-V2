@props([
    'title' => null,
    'preheader' => null,
    // The Illuminate\Mail\Message for the send in progress. Blade components
    // do not inherit the parent view's $message variable, so templates pass
    // it in (:mail-message="$message ?? null") to enable CID inline images.
    'mailMessage' => null,
])
@php
    // Brand name from the admin "Site -> name" setting. Read inline (like the
    // social links below) so it is certain in every mail-render scope without
    // depending on a view composer firing. Falls back to the literal brand.
    $siteName = \App\Models\SiteSetting::get('site.name', 'RshopRefills');
@endphp
{{--
    Shared branded email shell. Table-based + inline styles for broad email-client
    support (Gmail, Apple Mail, Outlook). Brand: blue-600 accent, navy heads, white
    card, no gradients. Used by every template in resources/views/emails/.
    Build content with <x-emails.button> and <x-emails.panel>.
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $title ?? $siteName }}</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; background: #eff6ff; }
        a { color: #2563eb; }
        .em-btn:hover { background: #1d4ed8 !important; }
        @media only screen and (max-width: 620px) {
            .em-card { width: 100% !important; border-radius: 0 !important; }
            .em-pad { padding-left: 24px !important; padding-right: 24px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#eff6ff;">
    @if ($preheader)
        <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#eff6ff; opacity:0;">{{ $preheader }}</div>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eff6ff;">
        <tr>
            <td align="center" style="padding:32px 16px;">

                <table role="presentation" class="em-card" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px -8px rgba(15,23,42,0.12);">
                    {{-- Brand accent bar --}}
                    <tr><td style="height:5px; background:#2563eb; line-height:5px; font-size:5px;">&nbsp;</td></tr>

                    {{-- Logo header. Flattened-on-white PNG, NOT the transparent
                         webp the site uses: email image proxies (Gmail) convert
                         webp to jpeg, which has no alpha — the transparency
                         renders as a solid black box behind the logo. Embedded
                         inline (CID) on real sends because remote fetches
                         through those proxies are unreliable; $message is only
                         set while actually sending, so render()/previews fall
                         back to the public URL. --}}
                    @php
                        // ?v= busts email-client image-proxy caches that may
                        // still hold a 404 from before the logo file shipped.
                        $emailLogoSrc = asset('assets/email-logo.png').'?v=2';
                        if ($mailMessage instanceof \Illuminate\Mail\Message && is_file(public_path('assets/email-logo.png'))) {
                            try {
                                $emailLogoSrc = $mailMessage->embed(public_path('assets/email-logo.png'));
                            } catch (\Throwable $e) {
                                // Keep the URL; a missing logo must never block the email.
                            }
                        }
                    @endphp
                    <tr>
                        <td align="center" class="em-pad" style="padding:32px 40px 4px;">
                            <img src="{{ $emailLogoSrc }}" alt="{{ $siteName }}" width="190" style="width:190px; max-width:62%; height:auto; display:block; background:#ffffff;">
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="em-pad" style="padding:20px 40px 38px; font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:#3f3f46; font-size:16px; line-height:1.65;">
                            {{ $slot }}
                        </td>
                    </tr>
                </table>

                {{-- Footer --}}
                <table role="presentation" class="em-card" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px;">
                    <tr>
                        <td class="em-pad" style="padding:24px 40px 8px; text-align:center; font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:#71717a; font-size:12px; line-height:1.7;">
                            <p style="margin:0 0 12px;">
                                <a href="{{ \App\Models\SiteSetting::get('social.facebook', 'https://facebook.com/rshoprefills') }}" style="color:#2563eb; text-decoration:none; margin:0 7px;">Facebook</a>
                                <a href="{{ \App\Models\SiteSetting::get('social.x', 'https://x.com/rshoprefills') }}" style="color:#2563eb; text-decoration:none; margin:0 7px;">X</a>
                                <a href="{{ \App\Models\SiteSetting::get('social.tiktok', 'https://tiktok.com/@rshoprefills') }}" style="color:#2563eb; text-decoration:none; margin:0 7px;">TikTok</a>
                                <a href="{{ \App\Models\SiteSetting::get('social.instagram', 'https://instagram.com/rshoprefills') }}" style="color:#2563eb; text-decoration:none; margin:0 7px;">Instagram</a>
                            </p>
                            <p style="margin:0 0 6px; color:#a1a1aa;">{{ $siteName }}, your digital marketplace for gift cards, eSIMs, top-ups and bills.</p>
                            <p style="margin:0 0 6px;">Need a hand? <a href="https://wa.me/19402386229" style="color:#2563eb; text-decoration:none;">Chat with support</a></p>
                            <p style="margin:0; color:#a1a1aa;">&copy; {{ date('Y') }} {{ $siteName }}. All rights reserved.</p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
