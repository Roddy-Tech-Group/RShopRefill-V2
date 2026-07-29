<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Wallet\Events\TransactionPinChanged;
use App\Domain\Wallet\Events\TransactionPinCreated;
use App\Domain\Wallet\Events\TransactionPinLocked;
use App\Domain\Wallet\Events\TransactionPinVerificationFailed;
use App\Models\Admin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Queue\ShouldQueue;

class AuditLogListener implements ShouldQueue
{
    /**
     * Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
     * A property rather than onQueue() because this listener implements
     * ShouldQueue without using the Queueable trait.
     */
    public $queue = 'default';

    public function __construct(private AuditLogService $auditLogService) {}

    public function handle($event): void
    {
        if ($event instanceof TransactionPinCreated) {
            $this->auditLogService->log('transaction_pin_created', $event->user, null, null, null, $event->user->id);
        } elseif ($event instanceof TransactionPinChanged) {
            $this->auditLogService->log('transaction_pin_changed', $event->user, null, null, null, $event->user->id);
        } elseif ($event instanceof TransactionPinVerificationFailed) {
            $this->auditLogService->log('transaction_pin_verification_failed', $event->user, null, null, ['attempts' => $event->user->transaction_pin_attempts], $event->user->id);
        } elseif ($event instanceof TransactionPinLocked) {
            $this->auditLogService->log('transaction_pin_locked', $event->user, null, null, null, $event->user->id);
        } elseif ($event instanceof Login) {
            $actorId = $event->user instanceof Admin ? null : $event->user->id;
            $this->auditLogService->log('user_login', $event->user, null, null, null, $actorId);
        } elseif ($event instanceof Failed) {
            $this->auditLogService->log('user_login_failed', $event->user, null, null, ['credentials' => collect($event->credentials)->except('password')->toArray()]);
        } elseif ($event instanceof PasswordReset) {
            $actorId = $event->user instanceof Admin ? null : $event->user->id;
            $this->auditLogService->log('password_reset', $event->user, null, null, null, $actorId);
        }
    }

    public function subscribe($events): array
    {
        return [
            TransactionPinCreated::class => 'handle',
            TransactionPinChanged::class => 'handle',
            TransactionPinVerificationFailed::class => 'handle',
            TransactionPinLocked::class => 'handle',
            Login::class => 'handle',
            Failed::class => 'handle',
            PasswordReset::class => 'handle',
        ];
    }
}
