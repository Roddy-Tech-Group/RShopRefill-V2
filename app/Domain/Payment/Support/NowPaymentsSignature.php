<?php

namespace App\Domain\Payment\Support;

/**
 * NOWPayments IPN signature verification. NOWPayments signs the JSON body with
 * its keys sorted alphabetically (recursively) using HMAC-SHA512, so the payload
 * must be sorted identically before hashing — hashing the raw request body only
 * matches when the gateway happens to send pre-sorted JSON.
 */
class NowPaymentsSignature
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function verify(array $payload, ?string $signature, ?string $secret): bool
    {
        // Fail closed: a missing or blank IPN secret must never validate —
        // an empty secret would let anyone forge signatures.
        if (! is_string($secret) || $secret === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        $signingData = json_encode(self::sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expected = hash_hmac('sha512', (string) $signingData, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Recursively sort an array by its keys so the JSON we sign byte-matches the
     * JSON NOWPayments signed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sortRecursive(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortRecursive($value);
            }
        }

        return $data;
    }
}
