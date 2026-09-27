<?php

namespace App\Domain\Reviews\Services;

use App\Domain\Order\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Support\Str;

/**
 * The rate-your-experience prompt: when to ask, what to ask about, and how the
 * answer becomes a review.
 *
 * The rule is the one the business set: a signed-in customer is asked at most
 * once a day, and never again once they have answered. Both clocks live on the
 * account. The answer lands in the same reviews table as the product-page form,
 * unpublished and flagged as a customer submission, so it queues for the same
 * admin approval and reaches the same carousel once approved.
 */
final class ExperiencePromptService
{
    public const SCORE_MIN = 1;

    public const SCORE_MAX = 10;

    public function isEnabled(): bool
    {
        // Off unless an admin has turned it on: an unseeded flag must not prompt anyone.
        return FeatureFlag::on('experience_prompt', false);
    }

    /** Minutes on the dashboard before the first ask of the day, as set in admin. */
    public function delayMinutes(): int
    {
        return max(0, (int) SiteSetting::get('features.experience_prompt_delay_minutes', 3));
    }

    public function shouldPrompt(User $user): bool
    {
        if (! $this->isEnabled() || $user->experience_rated_at !== null) {
            return false;
        }

        return $user->experience_prompted_at === null
            || $user->experience_prompted_at->lt(now()->startOfDay());
    }

    public function markPrompted(User $user): void
    {
        $user->forceFill(['experience_prompted_at' => now()])->save();
    }

    /**
     * The service line to ask about by name: the category of what the customer
     * most recently had delivered. Null when they have not bought anything yet,
     * and the question falls back to their experience in general.
     */
    public function subjectFor(User $user): ?string
    {
        $order = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('order_status', [OrderStatus::Completed, OrderStatus::PartiallyCompleted])
            ->with('items.category:id,name')
            ->latest()
            ->first();

        $name = $order?->items->first()?->category?->name;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Store the answer as a pending review. A second answer from the same
     * customer updates the first rather than adding a row, matching what the
     * product-page form does for a general review.
     */
    public function submit(User $user, int $score, ?string $comment, bool $anonymous): Review
    {
        $score = max(self::SCORE_MIN, min(self::SCORE_MAX, $score));
        $comment = trim((string) $comment);
        $name = trim((string) $user->name);

        $initials = Str::of($name)->explode(' ')->filter()
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->take(2)->implode('') ?: Str::upper(Str::substr($name, 0, 2));

        $payload = [
            'user_id' => $user->id,
            'order_id' => null,
            'author_name' => $name,
            'initials' => $initials,
            'body' => $comment !== '' ? $comment : null,
            // Stars are out of five; the prompt asks out of ten. Halving keeps
            // every step distinct (7 -> 3.5, 8 -> 4.0) and the card already
            // draws half stars.
            'rating' => $score / 2,
            'score' => $score,
            'category' => $this->subjectFor($user),
            'is_anonymous' => $anonymous,
            'source' => 'RshopRefill',
            'reviewed_at' => now()->toDateString(),
            'is_published' => false,
            'is_customer_submitted' => true,
        ];

        $existing = Review::query()
            ->where('user_id', $user->id)
            ->where('is_customer_submitted', true)
            ->where('is_published', false)
            ->whereNull('order_id')
            ->whereNotNull('score')
            ->first();

        $review = $existing ? tap($existing)->update($payload) : Review::create($payload);

        $user->forceFill(['experience_rated_at' => now()])->save();

        return $review;
    }
}
