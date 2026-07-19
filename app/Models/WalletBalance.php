<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletBalance extends Model
{
    protected $fillable = [
        'ledger_account_id',
        'currency',
        'balance',
        'locked_balance',
    ];

    public function ledgerAccount()
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}
