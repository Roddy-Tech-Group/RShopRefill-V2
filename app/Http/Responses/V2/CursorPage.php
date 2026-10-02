<?php

namespace App\Http\Responses\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Validation\ValidationException;

/**
 * One shape for every list /v2 returns.
 *
 * Cursor paging rather than page numbers, and the reason is specific rather than
 * fashionable. An app scrolls a list while the data underneath moves: a new order
 * arrives, a product is delisted. With page numbers, "page 2" is computed by
 * skipping the first N rows, so a row inserted above the window pushes one item
 * down into page 2 and the customer sees it twice; a row removed pulls one up and
 * they never see it at all. A cursor says "carry on after THIS row", so neither
 * happens.
 *
 * The cursor is opaque on purpose. The app stores it and hands it back; it must
 * never try to read or construct one, because what is inside it is ours to change.
 *
 *   {
 *     "data": [ ... ],
 *     "page": { "per_page": 24, "next_cursor": "eyJpZCI6NDJ9", "has_more": true }
 *   }
 *
 * No next_page_url: a full URL would bake this host into stored client state, and
 * we intend to move /v2 onto its own hostname.
 */
final class CursorPage
{
    /** Never let a client ask for an unbounded page. */
    public const MAX_PER_PAGE = 100;

    public const DEFAULT_PER_PAGE = 24;

    /**
     * @param  class-string<JsonResource>  $resource
     */
    public static function respond(CursorPaginator $paginator, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($paginator->items())->resolve(),
            'page' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * A page size the client asked for, clamped. A request for 10,000 rows is
     * either a bug or an attempt to make the database do our thinking for us.
     */
    public static function perPage(mixed $requested): int
    {
        $perPage = (int) $requested;

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * The cursor the client handed back, checked before the database sees it.
     *
     * The framework decodes a cursor trustingly: an empty object, a scalar, or a
     * column the query does not order by each surface as a 500 deep inside the
     * paginator. A cursor is client-held state, so a corrupt or tampered one is an
     * ordinary bad request and is refused as one, naming the field.
     *
     * @param  list<string>  $columns  the columns the list is ordered by, which are
     *                                 the only keys a genuine cursor can carry
     *
     * @throws ValidationException
     */
    public static function cursor(mixed $encoded, array $columns): ?Cursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $cursor = is_string($encoded) ? self::decode($encoded, $columns) : null;

        if ($cursor === null) {
            throw ValidationException::withMessages([
                'cursor' => 'That page marker is not valid. Please reload the list.',
            ]);
        }

        return $cursor;
    }

    /**
     * @param  list<string>  $columns
     */
    private static function decode(string $encoded, array $columns): ?Cursor
    {
        $json = base64_decode(str_replace(['-', '_'], ['+', '/'], $encoded), true);

        if ($json === false) {
            return null;
        }

        $parameters = json_decode($json, true);

        if (! is_array($parameters) || ! is_bool($parameters['_pointsToNextItems'] ?? null)) {
            return null;
        }

        unset($parameters['_pointsToNextItems']);

        if ($parameters === []) {
            return null;
        }

        foreach ($parameters as $column => $value) {
            if (! is_string($column) || ! in_array($column, $columns, true)) {
                return null;
            }

            if ($value !== null && ! is_scalar($value)) {
                return null;
            }
        }

        return Cursor::fromEncoded($encoded);
    }
}
