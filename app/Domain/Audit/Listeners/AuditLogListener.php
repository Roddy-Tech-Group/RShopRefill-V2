<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Wallet\Events\TransactionPinChanged;
use App\Domain\Wallet\Events\TransactionPinCreated;
use App\Domain\Wallet\Events\TransactionPinLocked;
use App\Domain\Wallet\Events\TransactionPinVerificationFailed;
use App\Models\Admin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
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
            $this->auditLogService->log($this->authAction('login', $event->user), $event->user, null, null, $this->adminActorContext($event->user), $this->actorIdFor($event->user));
        } elseif ($event instanceof Logout) {
            // Logout carries a null user when the session was already gone.
            if ($event->user !== null) {
                $this->auditLogService->log($this->authAction('logout', $event->user), $event->user, null, null, $this->adminActorContext($event->user), $this->actorIdFor($event->user));
            }
        } elseif ($event instanceof Failed) {
            // A failed attempt has no proven identity, so route by guard.
            $action = $event->guard === 'admin' ? 'admin_login_failed' : 'user_login_failed';
            $this->auditLogService->log($action, $event->user, null, null, ['credentials' => collect($event->credentials)->except('password')->toArray()]);
        } elseif ($event instanceof Lockout) {
            // Repeated failures tripped the login rate limiter.
            $this->auditLogService->log('auth_lockout', null, null, null, ['email' => $event->request->input('email'), 'ip' => $event->request->ip()]);
        } elseif ($event instanceof PasswordReset) {
            $this->auditLogService->log('password_reset', $event->user, null, null, $this->adminActorContext($event->user), $this->actorIdFor($event->user));
        }
    }

    /**
     * Auth actions are named by who acted: admin_login / user_login, and so
     * on. One taxonomy, no guessing from the target's model type.
     */
    private function authAction(string $verb, mixed $user): string
    {
        return ($user instanceof Admin ? 'admin_' : 'user_').$verb;
    }

    /**
     * The users.id FK only fits customer actors; admins ride in metadata.
     */
    private function actorIdFor(mixed $user): ?int
    {
        return $user instanceof Admin ? null : $user->id;
    }

    /**
     * This listener is queued, so by the time it runs the request's Auth
     * context is gone and AuditLogService cannot see the admin actor. Build
     * the admin attribution explicitly from the event's own user, otherwise
     * admin logins land in the log looking like "System".
     *
     * @return array<string, mixed>|null
     */
    private function adminActorContext(mixed $user): ?array
    {
        if (! $user instanceof Admin) {
            return null;
        }

        return [
            'actor_type' => Admin::class,
            'actor_id' => $user->getKey(),
            'actor_email' => $user->email,
            'actor_name' => $user->name,
        ];
    }

    public function subscribe($events): array
    {
        return [
            TransactionPinCreated::class => 'handle',
            TransactionPinChanged::class => 'handle',
            TransactionPinVerificationFailed::class => 'handle',
            TransactionPinLocked::class => 'handle',
            Login::class => 'handle',
            Logout::class => 'handle',
            Failed::class => 'handle',
            Lockout::class => 'handle',
            PasswordReset::class => 'handle',
        ];
    }
}
