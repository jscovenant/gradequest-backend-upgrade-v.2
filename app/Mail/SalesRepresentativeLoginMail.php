<?php

namespace App\Mail;

use App\Models\SalesRepresentative;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalesRepresentativeLoginMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SalesRepresentative $representative,
        public string $password,
        public string $loginUrl
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your GradeQuest Sales Representative Login Details',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.sales-representative-login',
            with: [
                'representative' => $this->representative,
                'user' => $this->representative->user,
                'password' => $this->password,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
