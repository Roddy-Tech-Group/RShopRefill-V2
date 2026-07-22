<?php

namespace App\Models;

use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Enums\Priority;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $type
 * @property string $title
 * @property string $message
 * @property CommunicationChannel $channel
 * @property DeliveryStatus $status
 * @property Priority $priority
 * @property array|null $metadata
 * @property Carbon|null $read_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Notification extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'channel',
        'status',
        'priority',
        'metadata',
        'read_at',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'status' => DeliveryStatus::class,
            'priority' => Priority::class,
            'metadata' => 'array',
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        $this->update(['read_at' => now()]);
    }
}
