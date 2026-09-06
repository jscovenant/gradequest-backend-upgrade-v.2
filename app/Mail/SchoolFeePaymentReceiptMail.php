<?php

namespace App\Mail;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SchoolFeePaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $receiptData,
        public bool $isSchoolAdmin = false
    ) {
    }

    public function envelope(): Envelope
    {
        $schoolName = $this->receiptData['school']->school_name ?? 'School';
        $receiptNo = $this->receiptData['receipt_no'] ?? $this->receiptData['reference'];
        $studentName = trim(($this->receiptData['student']->firstname ?? '') . ' ' . ($this->receiptData['student']->surname ?? ''));

        $subject = $this->isSchoolAdmin
            ? "Payment Notification: {$receiptNo} - {$studentName} ({$schoolName})"
            : "Official School Fee Receipt: {$receiptNo} - {$schoolName}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.fee_payment_receipt',
            with: [
                'data' => $this->receiptData,
                'isSchoolAdmin' => $this->isSchoolAdmin,
            ]
        );
    }

    public function attachments(): array
    {
        try {
            $pdf = Pdf::loadView('pdf.fee-payment-receipt', $this->receiptData)
                ->setPaper('a4', 'portrait');

            $filename = 'Receipt-' . ($this->receiptData['receipt_no'] ?? 'fee') . '.pdf';

            return [
                Attachment::fromData(fn () => $pdf->output(), $filename)
                    ->withMime('application/pdf'),
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
