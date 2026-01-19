<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MarketingEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $subjectLine;
    public $emailContent;

    public function __construct($subjectLine, $emailContent)
    {
        $this->subjectLine = $subjectLine;
        $this->emailContent = $emailContent;
    }

    /**
     * Set the email subject
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine
        );
    }

    /**
     * Set the email view and pass data to it
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.marketingMail',
            with: [
                'subjectLine' => $this->subjectLine,
                'emailContent' => $this->emailContent,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
