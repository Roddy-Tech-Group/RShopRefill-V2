<?php

namespace App\Http\Resources\V2;

use App\Models\WalletTransaction;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One movement in a wallet.
 *
 * `balance_after` is published because a statement people can reconcile is worth
 * far more than one they have to add up themselves, and because it is what lets a
 * customer point at the exact line where a figure stopped matching.
 *
 * `idempotency_key`, `source_type` and `source_id` are not published: they are our
 * internal plumbing, and source ids would expose row identifiers from tables the
 * app has no business knowing about.
 *
 * @property WalletTransaction $resource
 */
class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // currency and transaction_category are both cast to enums on the model,
        // so neither can be stringified directly.
        $currency = self::plain($this->currency) ?: 'USD';

        return [
            'id' => $this->id,
            // credit or debit.
            'type' => self::plain($this->type),
            'category' => self::plain($this->transaction_category),
            'amount' => ApiMoney::native((float) $this->amount, $currency),
            'balance_after' => ApiMoney::native((float) $this->balance_after, $currency),
            // Written for the customer. What they will look for when scanning a
            // statement for the line they do not recognise.
            'description' => $this->description,
            // Ours, but quotable to support, which is the point of showing it.
            'reference' => $this->reference,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * The plain value behind a possible enum cast. Several columns on this table are
     * backed enums, and publishing the object rather than its value is a 500 waiting
     * for the first statement anybody loads.
     */
    private static function plain(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }
}
