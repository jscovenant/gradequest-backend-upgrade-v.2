<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BroadcastNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $subject,
        public ?string $message,
        public ?string $waTemplate = null,
        public string $waLang = 'en_US',
        public array $waParams = [],
        public bool $sendEmail = true,
        public bool $sendWhatsApp = true
    ) {}

    public function via($notifiable): array
    {
        $channels = [];
        if ($this->sendEmail && !empty($notifiable->email)) $channels[] = 'mail';
        if ($this->sendWhatsApp && $this->waTemplate) $channels[] = WhatsAppChannel::class;
        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $name = trim(($notifiable->firstname ?? '').' '.($notifiable->surname ?? ''));

        return (new MailMessage)
            ->subject($this->subject ?? 'Announcement')
            ->greeting("Hello {$name}")
            ->line($this->message ?? '');
    }

    public function toWhatsApp($notifiable): array
    {
        return [
            'template' => $this->waTemplate,
            'lang' => $this->waLang,
            'params' => $this->waParams,
        ];
    }
}