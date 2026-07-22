<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Legacy delivery audit rows from the pre-Communication-Platform system.
 * New deliveries are recorded in communication_logs; this table is kept
 * read-only for historical data, so channel/status stay plain strings.
 *
 * @property int $id
 * @property string|null $notification_id
 * @property string $provider
 * @property string $channel
 * @property string $recipient
 * @property string $status
 * @property array|null $response_payload
 * @property string|null $error_message
 * @property Carbon $attempted_at
 */
class NotificationDelivery extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'notification_id',
        'provider',
        'channel',
        'recipient',
        'status',
        'response_payload',
        'error_message',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'attempted_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
