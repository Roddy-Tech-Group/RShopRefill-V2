<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'title',
        'notification_title',
        'notification_message',
        'notification_url',
        'channels',
        'category',
        'priority',
        'status',
        'audience_type',
        'audience_filters',
        'scheduled_at',
        'recurrence_rule',
        'sent_at',
        'stats_sent',
        'stats_delivered',
        'stats_failed',
        'stats_opened',
        'stats_clicked',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'audience_filters' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
