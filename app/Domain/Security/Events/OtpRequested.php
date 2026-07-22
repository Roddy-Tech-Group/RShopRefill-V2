<?php

namespace App\Domain\Security\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OtpRequested
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $context  Where the OTP was requested from: 'login', 'device', etc.
     */
    public function __construct(
        public User $user,
        public string $otp,
        public string $context = 'login',
    ) {}
}
