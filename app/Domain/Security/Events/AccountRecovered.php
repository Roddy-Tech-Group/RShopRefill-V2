<?php

namespace App\Domain\Security\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountRecovered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}
}
