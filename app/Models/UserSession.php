<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'trusted_device_id', 'laravel_session_id', 'access_token',
        'refresh_token', 'ip_address', 'user_agent', 'risk_level', 'expires_at', 'revoked_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(TrustedDevice::class, 'trusted_device_id');
    }

    public function isRevoked(): bool
    {
        return !is_null($this->revoked_at);
    }

    public function isActive(): bool
    {
        if ($this->isRevoked()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
