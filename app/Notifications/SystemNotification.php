<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;



class SystemNotification extends Notification
{
    use Queueable;

    protected string $message;
    protected string $actionUrl;
    protected string $type;

    public function __construct(string $message, string $actionUrl, string $type = 'info')
    {
        $this->message = $message;
        $this->type = $type;
        $this->actionUrl = $actionUrl;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'message' => $this->message,
            'type' => $this->type,
            'action_url' => $this->actionUrl,
        ];
    }
}

