<?php

namespace App\Notifications;

use App\Contracts\ShouldSendWhatsApp;
use App\Models\SchoolSetting;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class FeeInvoiceReminderNotification extends Notification implements ShouldQueue, ShouldSendWhatsApp
{
    use Queueable;

    public function __construct(
        public array $data,
        public bool $forceEmail = true,
        public bool $forceWhatsApp = true
    ) {}

    public function via($notifiable): array
    {
        $channels = [];

        if ($this->forceEmail && !empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        if ($this->forceWhatsApp) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $name = trim(($notifiable->firstname ?? '') . ' ' . ($notifiable->surname ?? ''));
        $name = $name ?: 'Parent/Guardian';

        $schoolId = (int) ($this->data['school_id'] ?? 0);
        $summaryType = (string) ($this->data['summary_type'] ?? 'single_invoice');

        $settings = SchoolSetting::where('id', $schoolId)->first();

        $logoUrl = null;
        if ($settings && !empty($settings->logo)) {
            $logoUrl = asset($settings->logo);
        }

        $schoolName = $settings->school_name ?? config('app.name', 'Your School');
        $schoolEmail = $settings->email ?? null;
        $schoolPhone = $settings->phone ?? null;
        $schoolAddress = $settings->address ?? null;
        $schoolSubdomain = $settings->school_subdomain ?? null;
        $customDomain = $settings->custom_domain ?? null;

  $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
$notificationId = $this->data['notification_id'] ?? null;

// Always prefer explicit payment URL from payload
$paymentUrl = $this->data['payment_url'] ?? null;

// If none was passed, build only from FRONTEND_URL + notification_id
if (!$paymentUrl) {
    if (!empty($frontendUrl) && !empty($notificationId)) {
        $paymentUrl = $frontendUrl . '/payment-instructions/' . $notificationId;
    } else {
        $paymentUrl = $frontendUrl ?: url('/');
    }
}

        if ($summaryType === 'combined_parent_fees') {
            return (new MailMessage)
                ->subject("Outstanding School Fees Summary - {$schoolName}")
                ->view('mail.fee-invoice-reminder', [
                    'name' => $name,
                    'logoUrl' => $logoUrl,
                    'schoolName' => $schoolName,
                    'children' => $this->data['children'] ?? [],
                    'totalAmount' => '₦' . number_format((float) ($this->data['total_amount'] ?? 0), 2),
                    'totalPaid' => '₦' . number_format((float) ($this->data['total_paid'] ?? 0), 2),
                    'totalBalance' => '₦' . number_format((float) ($this->data['total_balance'] ?? 0), 2),
                    'dueDate' => $this->data['due_date'] ?? 'N/A',
                    'paymentUrl' => $paymentUrl,
                    'schoolEmail' => $schoolEmail,
                    'schoolPhone' => $schoolPhone,
                    'schoolAddress' => $schoolAddress,
                    'schoolWebsite' => $paymentUrl,
                ]);
        }

        return (new MailMessage)
            ->subject("School Fees Reminder - Invoice {$this->data['invoice_no']}")
            ->view('mail.fee-invoice-reminder', [
                'name' => $name,
                'logoUrl' => $logoUrl,
                'schoolName' => $schoolName,
                'studentName' => $this->data['student_name'] ?? 'N/A',
                'invoiceNo' => $this->data['invoice_no'] ?? 'N/A',
                'amount' => '₦' . number_format((float) ($this->data['amount'] ?? 0), 2),
                'paid' => '₦' . number_format((float) ($this->data['paid'] ?? 0), 2),
                'balance' => '₦' . number_format((float) ($this->data['balance'] ?? 0), 2),
                'dueDate' => $this->data['due_date'] ?? 'N/A',
                'paymentUrl' => $paymentUrl,
                'schoolEmail' => $schoolEmail,
                'schoolPhone' => $schoolPhone,
                'schoolAddress' => $schoolAddress,
                'schoolWebsite' => $paymentUrl,
            ]);
    }





public function toWhatsApp($notifiable): array
{
    $parentName = trim((string) ($this->data['parent_name'] ?? 'Parent/Guardian'));
    $dueDate = trim((string) ($this->data['due_date'] ?? 'N/A'));
    $totalBalance = '₦' . number_format((float) ($this->data['total_balance'] ?? 0), 2);

    $lines = [];

    foreach (($this->data['children'] ?? []) as $child) {
        $studentName = $child['student_name'] ?? 'Student';
        $lines[] = $studentName;

        foreach (($child['items'] ?? []) as $item) {
            $feeTitle = $item['fee_title'] ?? 'Fee';
            $feeBalance = number_format((float) ($item['balance'] ?? 0), 2);
            $lines[] = "- {$feeTitle}: ₦{$feeBalance}";
        }
    }

    $messageText = "Outstanding school fees summary\n"
        . implode("\n", $lines)
        . "\nTotal Balance: {$totalBalance}";

    $payload = [
        'template' => 'combined_parent_fees_summary',
        'lang' => 'en',
        'params' => [
            $parentName !== '' ? $parentName : 'Parent/Guardian',
            $totalBalance,
            $dueDate !== '' ? $dueDate : 'N/A',
        ],
        'meta' => [
            'school_id' => (int) ($this->data['school_id'] ?? 0),
            'parent_id' => (int) ($this->data['parent_id'] ?? 0),
            'parent_name' => $parentName,
            'due_date' => $dueDate,
            'total_amount' => (float) ($this->data['total_amount'] ?? 0),
            'total_paid' => (float) ($this->data['total_paid'] ?? 0),
            'total_balance' => (float) ($this->data['total_balance'] ?? 0),
            'charge' => (float) ($this->data['whatsapp_charge'] ?? 5.0),
            'summary_type' => 'combined_parent_fees',
            'text' => $messageText,
        ],
    ];

    Log::info('FeeInvoiceReminderNotification combined_parent_fees WhatsApp payload', $payload);

    return $payload;
}
}