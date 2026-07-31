<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single account in the double-entry chart of accounts.
 *
 * `type` determines how a debit moves the balance: asset and expense accounts
 * increase on debit, while liability, equity and revenue accounts increase on
 * credit. Customer wallet balances are liabilities — money the platform owes.
 */
class LedgerAccount extends Model
{
    use HasFactory;

    /**
     * Account types whose balance increases on a debit; everything else
     * increases on a credit. Encoded once here so the projection maths and the
     * integrity verifier can never disagree about which way an entry moves.
     *
     * @var array<int, string>
     */
    public const DEBIT_POSITIVE_TYPES = ['asset', 'expense'];

    protected $fillable = [
        'name',
        'type',
        'currency',
        'reference_type',
        'reference_id',
    ];

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The cached balance projection the application reads instead of summing the
     * whole ledger on every request.
     *
     * @return HasOne<WalletBalance, $this>
     */
    public function balance(): HasOne
    {
        return $this->hasOne(WalletBalance::class);
    }

    /**
     * The domain object this account belongs to (a Wallet, a provider float, …).
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function increasesOnDebit(): bool
    {
        return in_array($this->type, self::DEBIT_POSITIVE_TYPES, true);
    }
}
