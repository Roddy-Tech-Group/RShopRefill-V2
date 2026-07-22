<?php

namespace App\Models;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Enums\MailDomain;
use App\Domain\Communication\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationLog extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'uuid',
        'user_id',
        'mail_domain',
        'category',
        'channel',
        'recipient',
        'sender',
        'subject',
        'template_key',
        'status',
        'provider',
        'provider_message_id',
        'priority',
        'correlation_id',
        'idempotency_key',
        'metadata',
        'queued_at',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'bounced_at',
        'failed_at',
        'error_message',
        'attempt_count',
        'max_attempts',
        'next_retry_at',
    ];

    protected $casts = [
        'mail_domain' => MailDomain::class,
        'category' => CommunicationCategory::class,
        'channel' => CommunicationChannel::class,
        'status' => DeliveryStatus::class,
        'priority' => Priority::class,
        'metadata' => 'array',

        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'bounced_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
