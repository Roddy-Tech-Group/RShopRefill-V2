<?php

namespace App\Domain\Communication\Mail\Marketing;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One newsletter campaign email — generic subject + body shipped to a single
 * subscriber. Built so the admin Newsletter page can compose either plain
 * text (rendered into paragraphs) or raw HTML and broadcast to every active
 * subscriber. Each recipient gets their own dispatched mailable so a single
 * bad address doesn't poison the whole batch.
 */
class NewsletterBroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyContent,
        public bool $isHtml = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter.broadcast',
            with: [
                'subjectLine' => $this->subjectLine,
                'bodyContent' => $this->bodyContent,
                'isHtml' => $this->isHtml,
            ],
        );
    }
}
