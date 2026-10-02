<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One refresh token in one device's chain.
 *
 * Single use: exchanging it stamps consumed_at and issues a successor in the
 * same family. Presenting a consumed one again is either the app retrying after
 * a dropped response or somebody replaying a stolen copy, and ApiTokenService
 * is what tells those apart.
 *
 * @property int $id
 * @property int $user_id
 * @property string $family_id
 * @property string $token_hash
 * @property int|null $personal_access_token_id
 * @property string|null $installation_id
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $revoked_at
 */
class ApiRefreshToken extends Model
{
    protected $fillable = [
        'user_id',
        'family_id',
        'token_hash',
        'personal_access_token_id',
        'installation_id',
        'expires_at',
        'consumed_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The access token issued alongside this one. Null once that token has been
     * retired, which rotation does deliberately.
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isConsumed() && ! $this->isExpired();
    }
}
