<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformMaintenanceNoticeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $admin,
        public string $customMessage,
        public ?string $startTime = null,
        public ?string $endTime = null,
        public bool $isImmediate = true
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Important Notice: Scheduled SchoolProfit Platform Maintenance',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.platform-maintenance-notice',
            with: [
                'admin' => $this->admin,
                'customMessage' => $this->customMessage,
                'startTime' => $this->startTime,
                'endTime' => $this->endTime,
                'isImmediate' => $this->isImmediate,
            ],
        );
    }
}
