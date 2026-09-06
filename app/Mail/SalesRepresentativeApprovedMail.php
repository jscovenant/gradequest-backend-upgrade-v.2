<?php

namespace App\Mail;

use App\Models\SalesRepresentative;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalesRepresentativeApprovedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SalesRepresentative $representative,
        public string $loginUrl
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Congratulations! Your SchoolProfit Sales Representative Account Has Been Approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.sales-rep-approved',
            with: [
                'representative' => $this->representative,
                'user' => $this->representative->user,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
