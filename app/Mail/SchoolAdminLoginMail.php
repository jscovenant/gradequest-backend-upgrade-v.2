<?php

namespace App\Mail;

use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SchoolAdminLoginMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $admin,
        public SchoolSetting $school,
        public string $password,
        public string $loginUrl
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your GradeQuest School Admin Login Details',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.school-admin-login',
            with: [
                'admin' => $this->admin,
                'school' => $this->school,
                'password' => $this->password,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
