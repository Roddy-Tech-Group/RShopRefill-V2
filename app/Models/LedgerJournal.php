<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The header of one double-entry transaction.
 *
 * A journal is only valid when its entries' debits and credits sum equal. That
 * invariant is enforced on write by LedgerService and re-proven independently
 * every night by LedgerIntegrityService — writing and verifying are deliberately
 * separate code paths, so a bug in one does not silently bless the other.
 */
class LedgerJournal extends Model
{
    use HasFactory;

    protected $fillable = [
        'description',
        'currency',
        'idempotency_key',
        'status',
        'correlation_id',
    ];

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
