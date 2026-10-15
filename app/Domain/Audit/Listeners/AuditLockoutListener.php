<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Services\AuditLogService;
use Illuminate\Auth\Events\Lockout;

/**
 * Login lockouts, written to the audit log as they happen rather than queued
 * with AuditLogListener's other events. A Lockout carries the live request,
 * which holds closures (its route and user resolvers) and cannot be serialized
 * onto a queue: queued, every lockout failed to dispatch, and the sixth wrong
 * password in a minute answered with a server error instead of "Too many login
 * attempts". One row, written now, is the whole cost.
 */
final class AuditLockoutListener
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function handle(Lockout $event): void
    {
        $this->auditLogService->log('auth_lockout', null, null, null, [
            'email' => $event->request->input('email'),
            'ip' => $event->request->ip(),
        ]);
    }
}
