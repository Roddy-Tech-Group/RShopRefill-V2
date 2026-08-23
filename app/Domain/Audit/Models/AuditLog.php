<?php

namespace App\Domain\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory;

    /**
     * Append-only: an audit trail that can be edited is not an audit trail.
     * Updates are refused at the model layer; corrections are new rows.
     * (Deletes are left to a future retention policy, never ad-hoc code.)
     */
    protected static function booted(): void
    {
        static::updating(fn () => false);
    }

    protected $fillable = [
        'actor_id',
        'action',
        'model_type',
        'model_id',
        'before_state',
        'after_state',
        'correlation_id',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'metadata' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'model_type', 'model_id');
    }
}
