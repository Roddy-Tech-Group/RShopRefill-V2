<?php

namespace App\Domain\Rewards\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RcoinEarned
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $kind  'cashback' | 'referral'
     */
    public function __construct(
        public User $user,
        public int $amount,
        public int $newBalance,
        public string $kind = 'cashback',
        public ?string $transactionId = null,
    ) {}
}
