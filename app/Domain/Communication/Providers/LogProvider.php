<?php

namespace App\Domain\Communication\Providers;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogProvider implements MailProviderInterface
{
    public function send(
        string $to,
        string $from,
        string $replyTo,
        string $subject,
        Mailable $mailable,
        array $metadata = []
    ): array {
        // Send using Laravel's default mailer, which is configured to 'log'
        // This will write the email contents to storage/logs/laravel.log
        try {
            // Parse the "From" header: "Display Name <email@domain.com>"
            $fromAddress = $from;
            $fromName = null;
            if (preg_match('/^(.*?)\s*<(.*?)>$/', $from, $matches)) {
                $fromName = trim($matches[1]);
                $fromAddress = trim($matches[2]);
            }

            // Stamp the correct "from" directly onto the Mailable's envelope.
            // This takes precedence over the Mailer singleton's cached global from.
            $mailable->from($fromAddress, $fromName);
            $mailable->replyTo($replyTo);
            $mailable->to($to)->send(app('mailer'));

            $id = 'log_'.Str::uuid()->toString();

            Log::info('Email logged successfully via LogProvider', [
                'to' => $to,
                'from' => $from,
                'subject' => $subject,
                'id' => $id,
            ]);

            return [
                'id' => $id,
                'message' => 'Logged successfully',
            ];
        } catch (\Throwable $e) {
            Log::error('LogProvider failed to render/log email', [
                'error' => $e->getMessage(),
                'to' => $to,
            ]);

            return [
                'message' => $e->getMessage(),
            ];
        }
    }
}
