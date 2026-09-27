<?php

namespace App\Domain\Payment\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one way this application talks to the card gateway.
 *
 * Every call used to be a bare bearer request with no timeout and no
 * back-off, so a hung gateway held a checkout request until PHP gave up, and
 * a 429 was answered by the next call a moment later. The gateway's own
 * guidance shapes the two clients here:
 *
 *  - It gives up on a hung request at 28 seconds and answers 503, so the
 *    total timeout leaves room for that answer to arrive rather than cutting
 *    the line first. A connection that cannot be opened in ten seconds is not
 *    going to be.
 *  - Reads (verify, listings, bank lists) are safe to repeat, so they retry
 *    with growing back-off and jitter on 429, 5xx and connection trouble,
 *    honouring a Retry-After when one is sent.
 *  - Charges are never retried here: a charge that timed out may already
 *    have been taken, and the gateway asks that the reference be looked up
 *    before anything is sent again. The providers do that lookup.
 *
 * A 429 seen by either client is noted on GatewayRateLimit so the bulk
 * sweepers stand down for the cool-down.
 */
final class FlutterwaveHttp
{
    public const GATEWAY = 'flutterwave';

    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const REQUEST_TIMEOUT_SECONDS = 35;

    /** Back-off between retried reads, in milliseconds, before jitter. */
    private const READ_RETRY_DELAYS_MS = [500, 1500, 3500];

    private const JITTER_MS = 250;

    /** A single attempt with timeouts: for charges and anything else that must not run twice. */
    public static function client(string $secretKey): PendingRequest
    {
        return Http::withToken($secretKey)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }

    /**
     * A read: retried with back-off on 429, 5xx and connection trouble. The
     * last answer is returned rather than thrown, so callers keep their own
     * failed() handling; a connection that never opens still throws.
     */
    public static function readClient(string $secretKey): PendingRequest
    {
        return self::client($secretKey)->retry(
            count(self::READ_RETRY_DELAYS_MS) + 1,
            function (int $attempt, \Throwable $exception): int {
                $retryAfter = $exception instanceof RequestException
                    ? (int) $exception->response->header('Retry-After')
                    : 0;
                $base = self::READ_RETRY_DELAYS_MS[$attempt - 1] ?? self::READ_RETRY_DELAYS_MS[count(self::READ_RETRY_DELAYS_MS) - 1];

                return max($retryAfter * 1000, $base) + random_int(0, self::JITTER_MS);
            },
            function (\Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if (! $exception instanceof RequestException) {
                    return false;
                }

                $status = $exception->response->status();

                if ($status === 429) {
                    GatewayRateLimit::noteRateLimited(self::GATEWAY, $exception->response->header('Retry-After'));
                }

                return $status === 429 || $status >= 500;
            },
            throw: false,
        );
    }

    /** Note a 429 on a call made with client(), which does not retry. */
    public static function noteIfRateLimited(Response $response): void
    {
        if ($response->status() === 429) {
            GatewayRateLimit::noteRateLimited(self::GATEWAY, $response->header('Retry-After'));
        }
    }
}
