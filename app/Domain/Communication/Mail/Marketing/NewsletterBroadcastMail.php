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

    /**
     * @param  string  $imageUrl  Absolute URL of the campaign banner, hosted under
     *                            /assets/newsletter by the admin composer. Empty
     *                            string means no banner. Linked rather than embedded
     *                            on purpose: a CID attachment would be duplicated
     *                            into every message of a bulk broadcast.
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyContent,
        public bool $isHtml = false,
        public string $imageUrl = '',
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
                'imageUrl' => $this->imageUrl,
            ],
        );
    }
}
