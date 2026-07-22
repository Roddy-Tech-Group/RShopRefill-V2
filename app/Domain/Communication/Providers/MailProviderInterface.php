<?php

namespace App\Domain\Communication\Providers;

use Illuminate\Contracts\Mail\Mailable;

interface MailProviderInterface
{
    /**
     * Send an email.
     *
     * @return array Raw provider response (must include 'id' on success).
     */
    public function send(
        string $to,
        string $from,
        string $replyTo,
        string $subject,
        Mailable $mailable,
        array $metadata = []
    ): array;
}
