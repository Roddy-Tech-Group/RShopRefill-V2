<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\OrderResource;
use App\Http\Responses\V2\CursorPage;
use App\Models\Order;
use App\Support\ApiV2Error;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Order history, and one order in detail.
 *
 * Read only. Nothing here changes an order: placing one is checkout's job and
 * paying for one is the payment endpoints'.
 *
 * Scoped to the caller on every query, never by trusting an id. An order number is
 * short and human-readable, which makes it convenient to quote to support and
 * guessable by anyone bored, so ownership is part of the WHERE clause rather than
 * a check afterwards.
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $orders = Order::where('user_id', $request->user()->id)
            ->when(
                $validated['status'] ?? null,
                fn ($query, $status) => $query->where('order_status', $status),
            )
            ->with(['items', 'paymentAttempts'])
            // Newest first, then id: the tiebreaker keeps cursor paging total, so
            // two orders placed in the same second cannot be skipped or repeated
            // as the customer scrolls.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                CursorPage::perPage($validated['per_page'] ?? null),
                ['*'],
                'cursor',
                CursorPage::cursor($validated['cursor'] ?? null, ['created_at', 'id']),
            );

        return CursorPage::respond($orders, OrderResource::class);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->with(['items', 'paymentAttempts'])
            ->first();

        if ($order === null) {
            // The same answer whether it never existed or belongs to somebody else,
            // so the endpoint cannot be used to confirm that an order number is
            // real.
            return ApiV2Error::make('order_not_found', 'That order could not be found.', 404);
        }

        return response()->json(['data' => (new OrderResource($order))->resolve()]);
    }
}
