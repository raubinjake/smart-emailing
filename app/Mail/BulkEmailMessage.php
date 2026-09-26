<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One rendered bulk message, with placeholders already substituted.
 */
class BulkEmailMessage extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $subjectLine  the rendered subject
     * @param  string  $bodyHtml     the rendered HTML body
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bulk',
            with: ['bodyHtml' => $this->bodyHtml],
        );
    }
}
