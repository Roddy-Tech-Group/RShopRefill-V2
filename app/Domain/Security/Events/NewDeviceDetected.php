<?php

namespace App\Domain\Security\Events;

use App\Models\TrustedDevice;
use Illuminate\Foundation\Events\Dispatchable;

class NewDeviceDetected
{
    use Dispatchable;

    public function __construct(
        public TrustedDevice $device
    ) {}
}
