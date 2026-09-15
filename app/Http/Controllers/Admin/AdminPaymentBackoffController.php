<?php

namespace App\Http\Controllers\Admin;

use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Http\Controllers\Controller;
use App\Models\PaymentBackoffEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/**
 * The one-tap release for a card-testing lock. Reached from the signed link
 * in the Telegram alert, behind the admin session: a lock that turns out to
 * be a real customer should cost the admin one tap, not a support ticket.
 */
class AdminPaymentBackoffController extends Controller
{
    public function __construct(private readonly PaymentBackoffManager $backoff) {}

    public function lift(PaymentBackoffEvent $event): RedirectResponse
    {
        $alreadyLifted = $event->isLifted();

        $this->backoff->liftLock($event, auth('admin')->id());

        $destination = $event->user_id !== null && Route::has('admin.customer')
            ? route('admin.customer', $event->user_id)
            : route('admin.dashboard');

        return redirect($destination)->with('status', $alreadyLifted
            ? 'This lock was already lifted.'
            : 'Lock lifted. The customer can pay again right away.');
    }
}
