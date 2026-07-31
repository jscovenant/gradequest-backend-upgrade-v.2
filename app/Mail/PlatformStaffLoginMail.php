<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformStaffLoginMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $password,
        public string $loginUrl
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your GradeQuest Platform Staff Login Details',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.platform-staff-login',
            with: [
                'user' => $this->user,
                'password' => $this->password,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
