<?php

namespace App\Http\Middleware;

use App\Domain\Payment\Support\NowPaymentsSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming webhook request securely.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $provider): Response
    {
        $payload = $request->getContent();

        // 1. Verify HMAC Signature
        if (! $this->verifySignature($request, $payload, $provider)) {
            Log::warning("Webhook signature verification failed for {$provider}", ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 2. Replay & Idempotency Protection
        // Providers usually send an event ID or transaction reference
        $eventId = $this->extractEventId($request, $provider);
        $idempotencyKey = $eventId ? "webhook:{$provider}:{$eventId}" : null;

        // Check if we've processed this exact webhook in the last 24 hours
        if ($idempotencyKey && Cache::has($idempotencyKey)) {
            Log::info("Webhook idempotency triggered for {$provider}: {$eventId}");

            return response()->json(['message' => 'Already processed'], 200);
        }

        $response = $next($request);

        // Only mark the event processed once the controller succeeded. Caching
        // before processing permanently swallows the gateway's retries when the
        // first delivery fails (e.g. attempt not yet persisted, transient 500).
        if ($idempotencyKey && $response->getStatusCode() < 300) {
            Cache::put($idempotencyKey, true, now()->addDays(1));
        }

        return $response;
    }

    private function verifySignature(Request $request, string $payload, string $provider): bool
    {
        switch ($provider) {
            case 'flutterwave':
                $signature = $request->header('verif-hash');
                $secret = config('services.flutterwave.webhook_hash');

                return is_string($signature) && is_string($secret) && hash_equals($secret, $signature);

            case 'maplerad':
                // Maplerad now uses Svix for webhooks
                $msgId = $request->header('svix-id');
                $timestamp = $request->header('svix-timestamp');
                $signatures = $request->header('svix-signature');

                if (! $msgId || ! $timestamp || ! $signatures) {
                    // Fallback to legacy HMAC SHA512 signature if Svix headers are missing
                    $legacySignature = $request->header('x-maplerad-signature');
                    $legacySecret = config('services.maplerad.secret_key');
                    if (! is_string($legacySecret) || ! is_string($legacySignature)) {
                        return false;
                    }
                    $expected = hash_hmac('sha512', $payload, $legacySecret);

                    return hash_equals($expected, $legacySignature);
                }

                $secret = config('services.maplerad.webhook_secret');
                if (! is_string($secret)) {
                    return false;
                }

                // Remove the 'whsec_' prefix if present
                $secretBytes = base64_decode(str_replace('whsec_', '', $secret));

                $signedContent = $msgId.'.'.$timestamp.'.'.$payload;
                $expectedSig = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));

                $passedSigs = explode(' ', (string) $signatures);
                foreach ($passedSigs as $versionedSig) {
                    $parts = explode(',', $versionedSig);
                    if (count($parts) === 2 && $parts[0] === 'v1') {
                        if (hash_equals($expectedSig, $parts[1])) {
                            return true;
                        }
                    }
                }

                return false;

            case 'nowpayments':
                // NOWPayments signs key-sorted JSON, not the raw body — hashing
                // the raw payload rejects every notification whose JSON isn't
                // already alphabetically sorted.
                return NowPaymentsSignature::verify(
                    $request->json()->all(),
                    $request->header('x-nowpayments-sig'),
                    config('services.nowpayments.ipn_secret')
                );

            case 'airalo':
                // Airalo issues partners only a client_id + client_secret pair, so
                // the client_secret is the signing key unless a dedicated webhook
                // secret has been arranged. The digest covers the raw body and
                // arrives hex-encoded, sometimes with a `sha256=` prefix.
                $secret = (string) (config('services.airalo.webhook_secret')
                    ?: config('services.airalo.client_secret', ''));

                if ($secret === '') {
                    // Unsigned requests are a local/testing convenience only.
                    // Staging handles real provider traffic and must stay closed,
                    // so this deliberately does not key off isProduction().
                    return app()->environment('local', 'testing');
                }

                $signature = (string) ($request->header('X-Airalo-Signature')
                    ?: $request->header('Signature')
                    ?: '');

                if ($signature === '') {
                    return false;
                }

                $signature = (string) preg_replace('/^sha256=/i', '', $signature);

                return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);

            default:
                return false;
        }
    }

    private function extractEventId(Request $request, string $provider): ?string
    {
        $data = $request->json()->all();

        switch ($provider) {
            case 'flutterwave':
                return $data['data']['id'] ?? null;
            case 'maplerad':
                return $request->header('svix-id') ?? ($data['id'] ?? null);
            case 'nowpayments':
                // NOWPayments sends one IPN per status change, all sharing the
                // same payment_id — keying on payment_id alone drops every
                // update after the first (including "finished"). Hash the body
                // so only byte-identical redeliveries dedupe; every state
                // change (and partially_paid top-up) still processes.
                $paymentId = $data['payment_id'] ?? null;

                return $paymentId !== null
                    ? $paymentId.':'.hash('sha256', $request->getContent())
                    : null;
            case 'airalo':
                // One ICCID legitimately produces several events (75 % usage, then
                // 90 %, then expiry warnings), so keying on the ICCID alone would
                // silently drop every notification after the first. Hashing the
                // body dedupes byte-identical redeliveries — and replays of a
                // captured request — while letting genuine state changes through.
                $iccid = $data['iccid'] ?? null;

                return $iccid !== null
                    ? $iccid.':'.hash('sha256', $request->getContent())
                    : null;
            default:
                return null;
        }
    }
}
