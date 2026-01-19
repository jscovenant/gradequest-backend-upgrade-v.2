<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
     use Queueable;

    protected $message;
    protected $actionUrl;
    protected $type;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $message, string $type = 'info', string $actionUrl = null)
    {
        $this->message = $message;
        $this->type = $type;
        $this->actionUrl = $actionUrl;
    }

    /**
     * Determine which channels to send notification through.
     */
    public function via($notifiable)
    {
        return ['database']; 
    }

    /**
     * Store notification data in the database.
     */
    public function toDatabase($notifiable)
    {
        return [
            'message' => $this->message,
            'type' => $this->type,
            'action_url' => $this->actionUrl,
        ];
    }
}
