<?php

namespace App\Mail;

use App\Models\SalesRepresentative;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalesRepresentativeApplicationReceivedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SalesRepresentative $representative
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SchoolProfit Partner Application Received — Under Review',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.sales-rep-application-received',
            with: [
                'representative' => $this->representative,
                'user' => $this->representative->user,
            ],
        );
    }
}
