<?php

namespace App\Domain\Wallet\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionPinLocked
{
    use Dispatchable, SerializesModels;

    /**
     * @param  bool  $permanent  true once the guess budget is spent for good and only a
     *                           password-gated reset reopens the PIN; false for the 15-minute pause
     */
    public function __construct(public User $user, public bool $permanent = false) {}
}
