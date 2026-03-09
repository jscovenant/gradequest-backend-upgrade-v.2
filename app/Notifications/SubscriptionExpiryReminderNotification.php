<?php

namespace App\Notifications;

use App\Models\SchoolSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiryReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $data) {}

    public function via($notifiable): array
    {
        $channels = [];

        if (!empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $name = trim(($notifiable->firstname ?? '') . ' ' . ($notifiable->surname ?? ''));
        $name = $name ?: 'Admin';

        $schoolId = (int) ($this->data['school_id'] ?? 0);
        $settings = null;

        if ($schoolId > 0) {
            $settings = SchoolSetting::where('id', $schoolId)->first();
        }

        $logoUrl = null;
        if ($settings && !empty($settings->logo)) {
            $logoUrl = asset($settings->logo);
        }

        $schoolName = $settings->school_name ?? config('app.name', 'GradeQuest');
        $schoolEmail = $settings->email ?? null;
        $schoolPhone = $settings->phone ?? null;
        $schoolAddress = $settings->address ?? null;

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $renewUrl = $this->data['renew_url'] ?? null;

        if (!$renewUrl) {
            $renewUrl = $frontendUrl ?: url('/');
        }

        return (new MailMessage)
            ->subject("Subscription Expiry Reminder - {$schoolName}")
            ->view('mail.subscription-expiry-reminder', [
                'name' => $name,
                'logoUrl' => $logoUrl,
                'schoolName' => $schoolName,
                'planName' => $this->data['plan_name'] ?? 'Subscription Plan',
                'status' => $this->data['status'] ?? 'active',
                'daysLeft' => (int) ($this->data['days_left'] ?? 0),
                'endsAt' => $this->data['ends_at'] ?? 'N/A',
                'startsAt' => $this->data['starts_at'] ?? 'N/A',
                'renewUrl' => $renewUrl,
                'schoolEmail' => $schoolEmail,
                'schoolPhone' => $schoolPhone,
                'schoolAddress' => $schoolAddress,
            ]);
    }
}