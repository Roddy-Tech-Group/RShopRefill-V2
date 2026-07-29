<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentSession;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Renders the payment QR for a crypto session as a branded SVG we generate
 * ourselves.
 *
 * The checkout previously built this URL against a third-party image service
 * (api.qrserver.com), which meant every customer's deposit address was sent
 * off-site to be rendered, and a compromise of that service could have swapped
 * the address shown to the customer for one of the attacker's. A payment address
 * must never leave our infrastructure to be drawn.
 *
 * The payload is read from the stored session rather than the query string, so
 * this can only ever encode an address we issued.
 */
class CryptoQrController extends Controller
{
    /** Rendered edge length in px. */
    private const SIZE = 320;

    /**
     * Logo box as a fraction of the QR's edge. Kept low deliberately: at 22% the
     * covered area is under 5% of the symbol, comfortably inside the 30% that
     * high error correction can reconstruct. Pushing this larger is what makes
     * branded QR codes fail to scan.
     */
    private const LOGO_RATIO = 0.22;

    private const LOGO_FILE = 'assets/icon-192.png';

    public function show(string $id): Response
    {
        $session = PaymentSession::where('id', $id)
            ->orWhere('client_reference', $id)
            ->firstOrFail();

        $payload = data_get($session->payment_payload, 'qr_payload')
            ?? data_get($session->payment_payload, 'pay_address');

        abort_if(blank($payload), 404);

        $svg = $this->renderQr((string) $payload);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            // The address is fixed for the life of the session, but it is
            // customer-specific — cache privately, never in a shared proxy.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function renderQr(string $payload): string
    {
        // High error correction is what buys the room for the logo. Without it,
        // covering the centre destroys data the scanner cannot recover.
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::SIZE, 1),
            new SvgImageBackEnd
        ));

        $svg = $writer->writeString($payload, 'utf-8', ErrorCorrectionLevel::H());

        return $this->overlayLogo($svg);
    }

    /**
     * Inject a centred logo badge into the finished QR SVG.
     *
     * Returns the QR untouched if the logo is missing — a plain but scannable
     * code is a far better failure than a broken image on a payment screen.
     */
    private function overlayLogo(string $svg): string
    {
        $logo = $this->logoDataUri();

        if ($logo === null || ! str_contains($svg, '</svg>')) {
            return $svg;
        }

        $box = round(self::SIZE * self::LOGO_RATIO, 2);
        $pad = round($box * 0.12, 2);
        $plate = round($box + ($pad * 2), 2);
        $plateXy = round((self::SIZE - $plate) / 2, 2);
        $logoXy = round((self::SIZE - $box) / 2, 2);
        $radius = round($plate * 0.22, 2);

        // White plate first so the logo never sits directly on QR modules, which
        // is what confuses scanners at the boundary.
        $overlay = sprintf(
            '<g><rect x="%s" y="%s" width="%s" height="%s" rx="%s" fill="#FFFFFF"/>'
            .'<image x="%s" y="%s" width="%s" height="%s" href="%s" preserveAspectRatio="xMidYMid meet"/></g>',
            $plateXy, $plateXy, $plate, $plate, $radius,
            $logoXy, $logoXy, $box, $box, $logo
        );

        return str_replace('</svg>', $overlay.'</svg>', $svg);
    }

    /**
     * Base64 logo, cached so the file is not re-read and re-encoded on every
     * render — this endpoint is hit once per payment screen load.
     */
    private function logoDataUri(): ?string
    {
        return Cache::remember('crypto-qr-logo', now()->addDay(), function (): ?string {
            $path = public_path(self::LOGO_FILE);

            if (! is_file($path)) {
                return null;
            }

            $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'jpg', 'jpeg' => 'image/jpeg',
                default => null,
            };

            if ($mime === null) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
        });
    }
}
