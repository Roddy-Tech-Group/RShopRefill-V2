<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One phone's push token.
 *
 * The token is globally unique because it identifies one app installation on one
 * physical phone, so it can only belong to one account at a time. Registering a
 * token takes it from whoever held it before, which is what stops a sold or shared
 * phone receiving the previous owner's order and wallet notifications.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $installation_id
 * @property string|null $platform
 * @property string|null $app_version
 * @property Carbon $last_registered_at
 */
class ExpoPushToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'installation_id',
        'platform',
        'app_version',
        'last_registered_at',
    ];

    protected function casts(): array
    {
        return ['last_registered_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
