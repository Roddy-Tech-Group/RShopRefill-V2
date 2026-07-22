<?php

namespace App\Models;

use App\Domain\Communication\Enums\InteractionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationInteraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_id',
        'user_id',
        'campaign_id',
        'channel',
        'interaction_type',
        'user_agent',
        'ip_address',
        'metadata',
        'interacted_at',
    ];

    protected function casts(): array
    {
        return [
            'interaction_type' => InteractionType::class,
            'metadata' => 'array',
            'interacted_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaign::class);
    }
}
