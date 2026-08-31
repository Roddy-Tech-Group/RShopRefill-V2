<?php

namespace App\Listeners;

use App\Domain\Auth\Services\AccountClosureService;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Signing in during the grace period is how a customer keeps their account.
 * Every sign-in path (password, Google, popup) fires Login, so this is the
 * single place the cancellation lives.
 */
class CancelAccountClosureOnLogin
{
    public function __construct(private readonly AccountClosureService $closures) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || $user->closure_requested_at === null || $user->isClosed()) {
            return;
        }

        $this->closures->cancel($user);

        session()->flash('account_closure_cancelled', 'Welcome back. Your account closure has been cancelled and your account stays open.');
    }
}
