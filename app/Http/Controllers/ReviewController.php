<?php

namespace App\Http\Controllers;

use App\Domain\Order\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Customer-submitted reviews. A shopper leaves a star rating + review; it is
 * stored unpublished + flagged as a customer submission so an admin approves it
 * before it appears on the storefront.
 *
 * Signed-in customers review under their own account name (so the review ties
 * back to them - verified badge + one-per-account dedup), and can attribute it
 * to a delivered order (via `order_id`) so its rating rolls up under every gift
 * card they bought. Guests review with a name they supply; their submission has
 * no account and no dedup, so admin moderation is the spam gate.
 */
class ReviewController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            // Signed-in customers review under their account name, so the field
            // is only required (and only trusted) for guests.
            'author_name' => [$user ? 'nullable' : 'required', 'string', 'min:2', 'max:80'],
            'body' => ['required', 'string', 'min:8', 'max:1000'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'order_number' => ['nullable', 'string', 'max:64'],
        ]);

        // Only a signed-in customer can attribute a review to an order, and only
        // to one that belongs to them and has actually been delivered. Anything
        // else is treated as tampering rather than silently downgraded.
        $order = null;
        if ($user && ! empty($validated['order_number'])) {
            $order = Order::where('order_number', $validated['order_number'])
                ->where('user_id', $user->id)
                ->whereIn('order_status', [OrderStatus::Completed, OrderStatus::PartiallyCompleted])
                ->first();

            abort_if(! $order, 422, 'That order cannot be reviewed.');
        }

        // Dedup only applies to signed-in customers: one pending review per order
        // for product reviews, or a single general review. Re-submitting updates
        // it in place. Guests have no account to dedup against, so each guest
        // submission is its own pending review (moderation filters spam).
        $existing = $user
            ? Review::where('user_id', $user->id)
                ->where('is_customer_submitted', true)
                ->where('is_published', false)
                ->when(
                    $order,
                    fn ($query) => $query->where('order_id', $order->id),
                    fn ($query) => $query->whereNull('order_id'),
                )
                ->first()
            : null;

        // Signed-in: name comes from the account (can't be spoofed). Guest: the
        // name they supplied.
        $name = trim($user ? $user->name : (string) $validated['author_name']);
        $initials = Str::of($name)->explode(' ')->filter()
            ->map(fn ($p) => Str::upper(Str::substr($p, 0, 1)))
            ->take(2)->implode('') ?: Str::upper(Str::substr($name, 0, 2));

        $payload = [
            'user_id' => $user?->id,
            'order_id' => $order?->id,
            'author_name' => $name,
            'initials' => $initials,
            'body' => trim($validated['body']),
            'rating' => (int) $validated['rating'],
            'source' => 'RshopRefill',
            'reviewed_at' => now()->toDateString(),
            'is_published' => false,
            'is_customer_submitted' => true,
        ];

        if ($existing) {
            $existing->update($payload);
        } else {
            Review::create($payload);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Thank you! Your review will appear once our team approves it.',
        ]);
    }
}
