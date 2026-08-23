<?php

namespace App\Domain\Payment\Support;

use Throwable;

/**
 * Extracts the diagnostic truth from a gateway failure response.
 *
 * Every payment failure has two audiences. The customer gets a polished
 * sentence with no gateway internals in it; that is the `message` key the
 * providers already return and nothing here changes it. The operator gets
 * THIS block: the gateway's own verdict, verbatim, so Payment Insights can
 * say "insufficient_funds" instead of "unknown_error".
 *
 * The block is attached to failed provider results under the `failure` key,
 * stored on the payment session by PaymentSessionService::failSession(), and
 * read by RecordPaymentOutcomeJob / FailureReasonClassifier. It is never
 * rendered to a customer.
 */
final class GatewayFailure
{
    /**
     * Flutterwave failure shapes, in diagnostic priority order:
     * `data.processor_response` is the ISSUER's verdict ("Insufficient
     * Funds", "05 Do Not Honor") and the single most useful string a card
     * failure carries; the top-level `message` is the gateway-level error
     * (bad key, wrong currency, validation).
     *
     * @param  array<string, mixed>|null  $body  Decoded response or webhook payload
     * @return array{provider_code: ?string, provider_message: ?string, http_status: ?int}
     */
    public static function fromFlutterwave(?array $body, ?int $httpStatus = null): array
    {
        $body ??= [];
        $inner = is_array($body['data'] ?? null) ? $body['data'] : [];

        $processor = self::firstString([
            $inner['processor_response'] ?? null,
            $body['processor_response'] ?? null,
        ]);

        return [
            'provider_code' => $processor,
            'provider_message' => self::firstString([
                $processor,
                $body['message'] ?? null,
                $inner['status'] ?? null,
            ]),
            'http_status' => $httpStatus,
        ];
    }

    /**
     * NOWPayments failure shapes: API errors carry `code` ("INVALID_API_KEY",
     * "AMOUNT_MINIMAL_ERROR") + `message`; IPN/status payloads carry
     * `payment_status` ("expired", "partially_paid", "failed").
     *
     * @param  array<string, mixed>|null  $body
     * @return array{provider_code: ?string, provider_message: ?string, http_status: ?int}
     */
    public static function fromNowPayments(?array $body, ?int $httpStatus = null): array
    {
        $body ??= [];

        return [
            'provider_code' => self::firstString([
                $body['code'] ?? null,
                $body['payment_status'] ?? null,
            ]),
            'provider_message' => self::firstString([
                $body['message'] ?? null,
                $body['payment_status'] ?? null,
            ]),
            'http_status' => $httpStatus,
        ];
    }

    /**
     * Paystack failure shapes: `data.gateway_response` carries the
     * processor/issuer verdict ("Declined", "Insufficient Funds") on both
     * verify responses and charge.failed webhooks; the top-level `message`
     * is the API-level error (bad key, validation). `status: true` at the
     * top level only means the HTTP call worked, so it is ignored here.
     *
     * @param  array<string, mixed>|null  $body  Decoded response or webhook payload
     * @return array{provider_code: ?string, provider_message: ?string, http_status: ?int}
     */
    public static function fromPaystack(?array $body, ?int $httpStatus = null): array
    {
        $body ??= [];
        $inner = is_array($body['data'] ?? null) ? $body['data'] : [];

        $gatewayResponse = self::firstString([
            $inner['gateway_response'] ?? null,
            $body['gateway_response'] ?? null,
        ]);

        return [
            'provider_code' => self::firstString([
                $gatewayResponse,
                $inner['status'] ?? null,
            ]),
            'provider_message' => self::firstString([
                $gatewayResponse,
                $body['message'] ?? null,
                $inner['status'] ?? null,
            ]),
            'http_status' => $httpStatus,
        ];
    }

    /**
     * Kora Pay failure shapes: verify/charge responses put the payment
     * verdict in `data.status` ("failed", "expired") and per-attempt detail
     * in `data.payment_attempts[].message`; API-level errors carry a
     * top-level `message` with `status: false`.
     *
     * @param  array<string, mixed>|null  $body  Decoded response or webhook payload
     * @return array{provider_code: ?string, provider_message: ?string, http_status: ?int}
     */
    public static function fromKorapay(?array $body, ?int $httpStatus = null): array
    {
        $body ??= [];
        $inner = is_array($body['data'] ?? null) ? $body['data'] : [];
        $attempts = is_array($inner['payment_attempts'] ?? null) ? $inner['payment_attempts'] : [];
        $lastAttempt = is_array(end($attempts)) ? end($attempts) : [];

        return [
            'provider_code' => self::firstString([
                $lastAttempt['message'] ?? null,
                $inner['status'] ?? null,
            ]),
            'provider_message' => self::firstString([
                $lastAttempt['message'] ?? null,
                $body['message'] ?? null,
                $inner['status'] ?? null,
            ]),
            'http_status' => $httpStatus,
        ];
    }

    /**
     * Local exceptions (connection timeouts, SDK errors) have no gateway
     * verdict; the exception text is the best diagnostic available and the
     * class name separates "their API hung" from "our code threw".
     *
     * @return array{provider_code: ?string, provider_message: ?string, http_status: ?int, exception_class: string}
     */
    public static function fromException(Throwable $e): array
    {
        return [
            'provider_code' => null,
            'provider_message' => $e->getMessage(),
            'http_status' => null,
            'exception_class' => get_class($e),
        ];
    }

    /**
     * A failure our own platform decided (mock declines, missing purchase id,
     * no outstanding balance) — code is ours, but the shape stays uniform.
     *
     * @return array{provider_code: string, provider_message: string, http_status: null}
     */
    public static function internal(string $code, ?string $message = null): array
    {
        return [
            'provider_code' => $code,
            'provider_message' => $message ?? $code,
            'http_status' => null,
        ];
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private static function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
