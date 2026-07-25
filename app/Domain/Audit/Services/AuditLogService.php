<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Log an action to the audit logs.
     *
     * `actor_id` carries a hard FK to users.id, so we only fill it from the
     * web / api customer guards. Admin actors are tracked in metadata instead
     * (admin lives in its own `admins` table, no FK match against `users`).
     * Without this branch a bare `Auth::id()` from the admin guard kicks back
     * a 1452 foreign-key violation on every admin login - which is exactly
     * the bug that flagged this method.
     */
    public function log(
        string $action,
        ?Model $model = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?array $metadata = null,
        ?int $actorId = null
    ): AuditLog {
        $resolvedActorId = null;
        $actorContext = null;

        if ($actorId !== null) {
            // Explicit actor id wins. Caller is responsible for making sure
            // it points at a real users.id (the FK will catch them otherwise).
            $resolvedActorId = $actorId;
        } else {
            $authedUser = Auth::user();

            if ($authedUser instanceof User) {
                $resolvedActorId = $authedUser->getKey();
            } elseif ($authedUser instanceof Admin) {
                // Admins don't belong to the users table, so we record their
                // identity in metadata. Downstream filters (admin reports,
                // SRE dashboards) can read actor_type/actor_id from there.
                $actorContext = [
                    'actor_type' => Admin::class,
                    'actor_id' => $authedUser->getKey(),
                    'actor_email' => $authedUser->email,
                ];
            } elseif ($authedUser !== null) {
                // Some other authenticatable - take the key but don't trust
                // the FK; null it out to avoid a constraint error.
                $actorContext = [
                    'actor_type' => $authedUser::class,
                    'actor_id' => $authedUser->getKey(),
                ];
            }
        }

        $mergedMetadata = $actorContext !== null
            ? array_merge($actorContext, $metadata ?? [])
            : $metadata;

        return AuditLog::create([
            'actor_id' => $resolvedActorId,
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model ? $model->getKey() : null,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'correlation_id' => Context::get('correlation_id'),
            // Prefer the request snapshot captured by TraceRequestMiddleware:
            // it survives the queue boundary (AuditLogListener is queued),
            // where the Request facade would report the worker, not the client.
            'ip_address' => Context::getHidden('request_ip') ?? Request::ip(),
            'user_agent' => Context::getHidden('request_user_agent') ?? Request::userAgent(),
            'metadata' => $mergedMetadata,
        ]);
    }
}
