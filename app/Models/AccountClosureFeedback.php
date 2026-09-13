<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Why a customer said they were closing their account. Optional, product
 * signal only; never used to contact anyone.
 *
 * @property int $id
 * @property int $user_id
 * @property list<string> $reasons
 * @property string|null $comment
 */
class AccountClosureFeedback extends Model
{
    protected $table = 'account_closure_feedback';

    /**
     * The reasons a customer can tick, keyed by the value stored.
     *
     * @var array<string, string>
     */
    public const REASONS = [
        'not_using' => 'I do not use it enough',
        'prices_fees' => 'Prices or fees',
        'payment_problems' => 'Payment or wallet problems',
        'missing_products' => 'Could not find what I wanted to buy',
        'another_service' => 'I prefer another service',
        'privacy' => 'Privacy concerns',
        'other' => 'Something else',
    ];

    /** @var list<string> */
    protected $fillable = ['user_id', 'reasons', 'comment'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reasons' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
