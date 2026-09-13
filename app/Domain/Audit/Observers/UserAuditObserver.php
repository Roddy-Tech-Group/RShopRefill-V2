<?php

namespace App\Domain\Audit\Observers;

use App\Domain\Audit\Services\AuditLogService;
use App\Models\User;

/**
 * Model-level auditing of the User columns that matter to compliance. Watching
 * the MODEL (not the controllers) means every surface that changes these fields
 * is captured automatically: the admin panel, the Smile webhook flipping
 * kyc_status, a console command, or code that does not exist yet. The actor is
 * resolved by AuditLogService from the authenticated context (customer id,
 * admin identity in metadata, or none for system/queue changes).
 *
 * Only the sensitive subset is recorded, and secret material is never included.
 */
class UserAuditObserver
{
    /**
     * Columns whose changes constitute an auditable account event.
     *
     * @var list<string>
     */
    private const SENSITIVE = [
        'name',
        'email',
        'phone',
        'country',
        'email_verified_at',
        'phone_verified_at',
        'kyc_status',
        'banned_at',
        'suspended_at',
        'restricted_at',
        'closure_requested_at',
        'anonymised_at',
        'rcoin_multiplier',
        'is_guest',
    ];

    public function __construct(private readonly AuditLogService $auditLog) {}

    public function updated(User $user): void
    {
        // Model-level so every path is caught: settings page, reset-by-email,
        // admin tools. The credential itself never lands in the log - only the
        // fact it changed.
        if (array_key_exists('password', $user->getChanges())) {
            $this->auditLog->log(
                action: 'user.password_changed',
                model: $user,
            );
        }

        $changed = array_intersect(array_keys($user->getChanges()), self::SENSITIVE);

        if ($changed === []) {
            return;
        }

        $before = [];
        $after = [];

        foreach ($changed as $field) {
            $before[$field] = $user->getOriginal($field);
            $after[$field] = $user->getAttribute($field);
        }

        $this->auditLog->log(
            action: 'user.sensitive_updated',
            model: $user,
            beforeState: $before,
            afterState: $after,
            metadata: ['fields' => array_values($changed)],
        );
    }
}
