<?php

namespace App\Http\Middleware;

use App\Support\ApiV2Error;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request carrying input the application can never legitimately
 * answer, with 422, before any controller sees it.
 *
 * Two classes of input, both found by a scanner probing the storefront:
 *
 *   1. Bytes that are not valid UTF-8. Any endpoint that echoes a parameter
 *      back into a JSON response turns these into a 500, because json_encode
 *      throws InvalidArgumentException on malformed UTF-8 and it throws while
 *      the response is being built, far from the validation that should have
 *      caught it. /cart/data and /cart/items both 500'd this way.
 *
 *   2. Absurdly long query-string values. A query parameter addresses
 *      something - a currency, a page, a slug - and nothing legitimate needs
 *      more than a couple of kilobytes.
 *
 * Request BODIES are checked for encoding but deliberately not for length: a
 * newsletter campaign's HTML body and a product description are both
 * legitimately long. Total body size is already capped by post_max_size and
 * Laravel's ValidatePostSize.
 *
 * This is a backstop, not a substitute for per-endpoint validation. Each
 * endpoint still allowlists its own parameters; this only guarantees that
 * garbage fails as a 422 the client can read rather than a 500 that pages
 * somebody at 2am.
 */
class RejectMalformedInput
{
    /**
     * Longest a single query-string value may be. Far above anything the app
     * builds (the longest real one is an OAuth code at a few hundred bytes)
     * and far below what a payload-stuffing probe sends.
     */
    private const MAX_QUERY_VALUE = 2048;

    /**
     * Deepest nesting walked. A request whose only purpose is to make this
     * middleware recurse is itself the attack, so stop rather than follow.
     */
    private const MAX_DEPTH = 10;

    public function handle(Request $request, Closure $next): Response
    {
        // Query string: encoding AND length.
        foreach ($request->query->all() as $key => $value) {
            if (! $this->encodingOk($key) || ! $this->valueOk($value, self::MAX_QUERY_VALUE)) {
                return $this->refuse($request);
            }
        }

        // Everything else (form body, JSON body): encoding only. Reading it
        // can itself throw on a malformed JSON body, which is equally a 422.
        try {
            $input = $request->except(array_keys($request->allFiles()));
        } catch (\Throwable) {
            return $this->refuse($request);
        }

        foreach ($input as $key => $value) {
            if (! $this->encodingOk($key) || ! $this->valueOk($value, null)) {
                return $this->refuse($request);
            }
        }

        return $next($request);
    }

    /**
     * Recursively checks one input value. $maxLength null means encoding only.
     */
    private function valueOk(mixed $value, ?int $maxLength, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (! $this->encodingOk($key) || ! $this->valueOk($item, $maxLength, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if (! is_string($value)) {
            return true;
        }

        if ($maxLength !== null && strlen($value) > $maxLength) {
            return false;
        }

        return $this->encodingOk($value);
    }

    /**
     * Array keys arrive as int for list-shaped input; only strings can carry
     * a bad encoding.
     */
    private function encodingOk(mixed $key): bool
    {
        return ! is_string($key) || mb_check_encoding($key, 'UTF-8');
    }

    /**
     * Deliberately terse and identical for both causes: a scanner learns
     * nothing about which parameter it got wrong, or how long is too long.
     */
    private function refuse(Request $request): Response
    {
        $message = 'The request contained malformed or oversized input.';

        // /v2 promises the app ONE error shape. This middleware is global, so it
        // sits in front of /v2 too, and a bare {"message": ...} here would be the
        // one response the app cannot read: it branches on error.type, and an
        // installed build cannot be corrected.
        if ($request->is('v2', 'v2/*')) {
            return ApiV2Error::make('malformed_request', $message, 422);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return response($message, 422);
    }
}
