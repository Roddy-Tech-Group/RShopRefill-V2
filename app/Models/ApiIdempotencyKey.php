<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One remembered answer, keyed by the intent the client named.
 *
 * See the migration for why this exists. In short: a retried request must not be
 * performed twice, and on mobile data retries are routine rather than rare.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key_hash
 * @property string $method
 * @property string $path
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_status
 * @property string|null $response_body
 * @property Carbon|null $locked_until
 * @property Carbon|null $completed_at
 * @property Carbon $expires_at
 */
class ApiIdempotencyKey extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'user_id',
        'key_hash',
        'method',
        'path',
        'request_hash',
        'status',
        'response_status',
        'response_body',
        'locked_until',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'locked_until' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'response_status' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Whether the first attempt is still believed to be running. Once the lock
     * lapses the attempt is presumed dead and a retry may take the row over,
     * which is what stops a request killed mid-flight from wedging that intent
     * for the whole 24 hours.
     */
    public function isLocked(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS
            && $this->locked_until !== null
            && $this->locked_until->isFuture();
    }

    /**
     * Did this key arrive with the same intent it was first spent on? A key
     * re-used for different details is a client bug, and answering it with the
     * old result would hide that bug behind a stale success.
     */
    public function matches(string $method, string $path, string $requestHash): bool
    {
        return $this->method === $method
            && $this->path === $path
            && hash_equals($this->request_hash, $requestHash);
    }
}
