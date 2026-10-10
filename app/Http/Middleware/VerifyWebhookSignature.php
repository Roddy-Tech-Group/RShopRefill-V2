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

            case 'paystack':
                // Paystack signs the RAW request body with HMAC-SHA512 using
                // the merchant secret key; the digest arrives hex-encoded in
                // x-paystack-signature. No separate webhook secret exists.
                $paystackSecret = config('services.paystack.secret_key');
                $paystackSignature = $request->header('x-paystack-signature');

                if (! is_string($paystackSecret) || $paystackSecret === '' || ! is_string($paystackSignature)) {
                    return false;
                }

                return hash_equals(hash_hmac('sha512', $payload, $paystackSecret), $paystackSignature);

            case 'korapay':
                // Kora's quirk: the HMAC-SHA256 covers the webhook's `data`
                // OBJECT ONLY, serialised as Kora sent it, keyed with the
                // merchant secret key. The bytes Kora signed are the `data`
                // object exactly as it sits in the raw body, so that substring
                // is hashed first; re-encodings of the parsed object (the
                // request bag has been trimmed and emptied by the global
                // middleware, and PHP escapes "/" and accents where Kora's
                // JSON.stringify does not) are only fallbacks.
                $korapaySecret = config('services.korapay.secret_key');
                $korapaySignature = $request->header('x-korapay-signature');

                if (! is_string($korapaySecret) || $korapaySecret === '' || ! is_string($korapaySignature)) {
                    return false;
                }

                foreach ($this->korapaySignedCandidates($request->getContent()) as $candidate) {
                    if (hash_equals(hash_hmac('sha256', $candidate, $korapaySecret), $korapaySignature)) {
                        return true;
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
            case 'paystack':
                // Paystack retries undelivered events for 72 hours; only
                // byte-identical redeliveries should dedupe, so key on the
                // charge reference plus a body hash (the NOWPayments lesson:
                // reference alone drops every later status change).
                $reference = $data['data']['reference'] ?? null;

                return $reference !== null
                    ? $reference.':'.hash('sha256', $request->getContent())
                    : null;

            case 'korapay':
                // Same 72-hour retry model as Paystack: dedupe byte-identical
                // redeliveries only, keyed on reference + body hash.
                $korapayReference = $data['data']['payment_reference']
                    ?? $data['data']['reference']
                    ?? null;

                return $korapayReference !== null
                    ? $korapayReference.':'.hash('sha256', $request->getContent())
                    : null;

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

    /**
     * The byte strings Kora may have signed for this body: the `data` object
     * cut out of the raw body first, then re-encodings of the parsed object.
     *
     * @return list<string>
     */
    private function korapaySignedCandidates(string $raw): array
    {
        $candidates = [];

        if (($cut = $this->jsonObjectAfterKey($raw, 'data')) !== null) {
            $candidates[] = $cut;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded) && is_array($decoded['data'] ?? null)) {
            $candidates[] = (string) json_encode($decoded['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $candidates[] = (string) json_encode($decoded['data']);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * The raw text of the JSON object that is the value of a top-level key,
     * or null when the body has no such object. Walks the braces, skipping
     * over strings, so a brace inside a name cannot cut it short.
     */
    private function jsonObjectAfterKey(string $raw, string $key): ?string
    {
        if (! preg_match('/"'.preg_quote($key, '/').'"\s*:\s*\{/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $inString = false;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];

            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
