<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg of a double-entry transaction.
 *
 * `amount` is always a positive integer in minor units (kobo, cents); direction
 * carries the sign. Storing signed amounts would let a single leg silently flip
 * a journal's balance, so the sign lives in exactly one place.
 */
class LedgerEntry extends Model
{
    use HasFactory;

    public const DIRECTION_DEBIT = 'debit';

    public const DIRECTION_CREDIT = 'credit';

    protected $fillable = [
        'ledger_journal_id',
        'ledger_account_id',
        'amount',
        'direction',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Minor units. Cast explicitly because PDO returns BIGINT as a string
            // on some drivers, and a string would break the strict debit/credit
            // equality comparison the integrity verifier depends on.
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LedgerJournal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'ledger_journal_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * @param  Builder<LedgerEntry>  $query
     */
    public function scopeDebits(Builder $query): void
    {
        $query->where('direction', self::DIRECTION_DEBIT);
    }

    /**
     * @param  Builder<LedgerEntry>  $query
     */
    public function scopeCredits(Builder $query): void
    {
        $query->where('direction', self::DIRECTION_CREDIT);
    }
}
