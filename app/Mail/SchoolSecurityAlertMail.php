<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SchoolSecurityAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $eventTitle;
    public $eventDetails;
    public $ipAddress;
    public $deviceInfo;
    public $schoolName;

    public function __construct(
        User $user,
        string $eventTitle,
        array $eventDetails = [],
        ?string $ipAddress = null,
        ?string $deviceInfo = null,
        ?string $schoolName = null
    ) {
        $this->user = $user;
        $this->eventTitle = $eventTitle;
        $this->eventDetails = $eventDetails;
        $this->ipAddress = $ipAddress ?: request()->ip();
        $this->deviceInfo = $deviceInfo ?: request()->userAgent();
        $this->schoolName = $schoolName ?: ($user->school?->school_name ?? 'Your School');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[SECURITY ALERT] {$this->eventTitle} - SchoolProfit",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.school_security_alert',
            with: [
                'user' => $this->user,
                'eventTitle' => $this->eventTitle,
                'eventDetails' => $this->eventDetails,
                'ipAddress' => $this->ipAddress,
                'deviceInfo' => $this->deviceInfo,
                'schoolName' => $this->schoolName,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
