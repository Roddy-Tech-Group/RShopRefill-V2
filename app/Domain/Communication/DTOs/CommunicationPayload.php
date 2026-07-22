<?php

namespace App\Domain\Communication\DTOs;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\MailDomain;
use App\Domain\Communication\Enums\Priority;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable data envelope that flows through the entire communication pipeline.
 *
 * Created by CommunicationManager, serialized into DispatchCommunicationJob,
 * and consumed by each Channel implementation.
 */
class CommunicationPayload
{
    public readonly MailDomain $mailDomain;

    public readonly Priority $priority;

    /** @var CommunicationChannel[] */
    public readonly array $channels;

    /**
     * @param  Model  $recipient  The user or admin receiving this communication.
     * @param  CommunicationCategory  $category  Business category driving routing.
     * @param  string  $title  Short title for in-app / push.
     * @param  string  $message  Body text for in-app / push.
     * @param  Mailable|null  $mailable  The email template (null = no email).
     * @param  Priority|null  $priority  Override; auto-derived from category if null.
     * @param  CommunicationChannel[]  $channels  Override; auto-resolved if empty.
     * @param  string|null  $correlationId  Links back to the triggering business event.
     * @param  string|null  $idempotencyKey  Prevents duplicate delivery.
     * @param  string|null  $overrideEmail  Redirects email to a different inbox (gifts).
     * @param  array  $metadata  Arbitrary context carried through to logs.
     */
    public function __construct(
        public readonly Model $recipient,
        public readonly CommunicationCategory $category,
        public readonly string $title,
        public readonly string $message,
        public readonly ?Mailable $mailable = null,
        ?Priority $priority = null,
        array $channels = [],
        public readonly ?string $correlationId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $overrideEmail = null,
        public readonly array $metadata = [],
    ) {
        $this->mailDomain = $category->mailDomain();
        $this->priority = $priority ?? Priority::forCategory($category);
        $this->channels = $channels;
    }

    /**
     * The inbox the email channel should deliver to.
     * Falls back to the recipient's email. Override is used for gifting.
     */
    public function emailRecipient(): string
    {
        return $this->overrideEmail ?: $this->recipient->email;
    }

    /**
     * Build the formatted "From" header from the resolved mail domain.
     */
    public function fromHeader(): string
    {
        return $this->mailDomain->fromHeader();
    }

    /**
     * Reply-To address — always routes to support.
     */
    public function replyTo(): string
    {
        return MailDomain::replyTo();
    }

    /**
     * Whether the recipient is the admin notification target.
     */
    public function isAdminRecipient(): bool
    {
        return $this->recipient->email === config('communication.admin_email');
    }
}
