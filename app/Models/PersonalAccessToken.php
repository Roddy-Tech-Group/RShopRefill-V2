<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * A mobile app sign-in. Sanctum's token, plus the phone it belongs to and the
 * security centre session it is recorded as, so the website's own controls
 * (remove a device, sign out everywhere, change the password) end it too.
 *
 * @property int|null $trusted_device_id
 * @property int|null $user_session_id
 * @property string|null $password_fingerprint
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** @return BelongsTo<TrustedDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(TrustedDevice::class, 'trusted_device_id');
    }

    /** @return BelongsTo<UserSession, $this> */
    public function userSession(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'user_session_id');
    }

    /**
     * The fingerprint a password hash leaves on a token. Only ever compared,
     * never reversed: a sha256 of a bcrypt hash says nothing about the password.
     */
    public static function fingerprint(?string $passwordHash): string
    {
        return hash('sha256', (string) $passwordHash);
    }
}
