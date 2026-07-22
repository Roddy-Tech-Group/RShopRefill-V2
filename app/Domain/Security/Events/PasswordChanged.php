<?php

namespace App\Domain\Security\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class PasswordChanged
{
    use Dispatchable;

    public function __construct(
        public User $user
    ) {}
}
