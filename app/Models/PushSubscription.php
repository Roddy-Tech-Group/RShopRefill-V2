<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $subscribable_type
 * @property int $subscribable_id
 * @property string $endpoint
 * @property string $p256dh_key
 * @property string $auth_token
 * @property string|null $user_agent
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'endpoint',
        'p256dh_key',
        'auth_token',
        'user_agent',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }
}
