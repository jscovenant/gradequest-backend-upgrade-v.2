<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SchoolOperatorWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $operator;
    public $password;
    public $schoolName;
    public $title;

    public function __construct(User $operator, string $password, ?string $schoolName = null, ?string $title = 'School Operator')
    {
        $this->operator = $operator;
        $this->password = $password;
        $this->schoolName = $schoolName ?: ($operator->school?->school_name ?? 'Your School');
        $this->title = $title;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your SchoolProfit Operator Login Credentials - {$this->schoolName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.school_operator_welcome',
            with: [
                'operator' => $this->operator,
                'password' => $this->password,
                'schoolName' => $this->schoolName,
                'title' => $this->title,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
