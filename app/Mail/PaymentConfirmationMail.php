<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $payment;
    public $subscription;

    public function __construct($user, $payment, $subscription = null)
    {
        $this->user = $user;
        $this->payment = $payment;
        $this->subscription = $subscription;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Confirmation!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.payment_confirmation',
            with: [
                'user' => $this->user,
                'payment' => $this->payment,
                'subscription' => $this->subscription, // ✅ make it available in the view
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
