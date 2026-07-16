<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming webhook request securely.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $provider): Response
    {
        $payload = $request->getContent();
        
        // 1. Verify HMAC Signature
        if (!$this->verifySignature($request, $payload, $provider)) {
            Log::warning("Webhook signature verification failed for {$provider}", ['ip' => $request->ip()]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 2. Replay & Idempotency Protection
        // Providers usually send an event ID or transaction reference
        $eventId = $this->extractEventId($request, $provider);
        if ($eventId) {
            $idempotencyKey = "webhook:{$provider}:{$eventId}";
            
            // Check if we've processed this exact webhook in the last 24 hours
            if (Cache::has($idempotencyKey)) {
                Log::info("Webhook idempotency triggered for {$provider}: {$eventId}");
                return response()->json(['message' => 'Already processed'], 200);
            }
            
            Cache::put($idempotencyKey, true, now()->addDays(1));
        }

        return $next($request);
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
                
                if (!$msgId || !$timestamp || !$signatures) {
                    // Fallback to legacy HMAC SHA512 signature if Svix headers are missing
                    $legacySignature = $request->header('x-maplerad-signature');
                    $legacySecret = config('services.maplerad.secret_key');
                    if (!is_string($legacySecret) || !is_string($legacySignature)) return false;
                    $expected = hash_hmac('sha512', $payload, $legacySecret);
                    return hash_equals($expected, $legacySignature);
                }

                $secret = config('services.maplerad.webhook_secret');
                if (!is_string($secret)) return false;

                // Remove the 'whsec_' prefix if present
                $secretBytes = base64_decode(str_replace('whsec_', '', $secret));
                
                $signedContent = $msgId . '.' . $timestamp . '.' . $payload;
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
                $signature = $request->header('x-nowpayments-sig');
                $secret = config('services.nowpayments.ipn_secret');
                if (!is_string($secret) || !is_string($signature)) return false;
                $expected = hash_hmac('sha512', $payload, $secret);
                return hash_equals($expected, $signature);

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
                return $data['payment_id'] ?? null;
            default:
                return null;
        }
    }
}
