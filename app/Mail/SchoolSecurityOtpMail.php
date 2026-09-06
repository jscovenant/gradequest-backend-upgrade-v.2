<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SchoolSecurityOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $otp;
    public $actionDescription;
    public $schoolName;

    public function __construct(User $user, string $otp, string $actionDescription = 'confirm a sensitive security action', ?string $schoolName = null)
    {
        $this->user = $user;
        $this->otp = $otp;
        $this->actionDescription = $actionDescription;
        $this->schoolName = $schoolName ?: ($user->school?->school_name ?? 'Your School');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[SECURITY OTP] {$this->otp} - SchoolProfit School Security Verification",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.school_security_otp',
            with: [
                'user' => $this->user,
                'otp' => $this->otp,
                'actionDescription' => $this->actionDescription,
                'schoolName' => $this->schoolName,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
