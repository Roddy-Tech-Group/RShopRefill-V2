<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrustedDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'device_uuid', 'device_name', 'browser', 'os', 'device_type',
        'ip_address', 'country', 'country_code', 'city', 'timezone', 'user_agent', 'fingerprint',
        'trusted_since', 'trust_score', 'current_session_count', 'last_seen_at',
    ];

    protected $casts = [
        'trusted_since' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function isTrusted(): bool
    {
        return ! is_null($this->trusted_since);
    }
}
