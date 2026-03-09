<?php

namespace App\Notifications\Channels;

use App\Contracts\ShouldSendWhatsApp;
use App\Services\WalletService;
use App\Services\WhatsAppCloudClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    public function __construct(
        private WalletService $wallets,
        private WhatsAppCloudClient $wa
    ) {}

    public function send($notifiable, Notification $notification): void
    {
        if (!$notification instanceof ShouldSendWhatsApp) {
            Log::warning('WhatsAppChannel: notification does not implement ShouldSendWhatsApp', [
                'notification' => get_class($notification),
            ]);
            return;
        }

        $payload = $notification->toWhatsApp($notifiable);

        Log::info('WhatsAppChannel: payload received', [
            'user_id' => $notifiable->id ?? null,
            'phone' => $notifiable->phone ?? null,
            'payload' => $payload,
        ]);

        $template = $payload['template'] ?? null;
        $lang     = $payload['lang'] ?? 'en';
        $params   = $payload['params'] ?? [];
        $meta     = $payload['meta'] ?? [];

        if (!empty($meta['skip_send'])) {
            Log::warning('WhatsAppChannel: skipped sending', [
                'user_id' => $notifiable->id ?? null,
                'reason' => $meta['reason'] ?? 'No reason supplied',
                'summary_type' => $meta['summary_type'] ?? null,
            ]);
            return;
        }

        if (!$template) {
            Log::warning('WhatsAppChannel: missing template; skipping', [
                'user_id' => $notifiable->id ?? null,
                'payload' => $payload,
            ]);
            return;
        }

        if ($template === 'hello_world') {
            $params = [];
        }

        $schoolId = isset($meta['school_id']) ? (int) $meta['school_id'] : null;
        if (!$schoolId) {
            Log::warning('WhatsAppChannel: missing school_id meta', [
                'user_id' => $notifiable->id ?? null,
                'template' => $template,
            ]);
            return;
        }

        $toPhone = $notifiable->phone ?? null;
        if (!$toPhone) {
            Log::warning('WhatsAppChannel: notifiable has no phone; skipping debit', [
                'user_id' => $notifiable->id ?? null,
                'template' => $template,
            ]);
            return;
        }

        $toPhone = $this->normalizePhone($toPhone);

        $cost = isset($meta['charge'])
            ? (float) $meta['charge']
            : $this->priceForTemplate($template);

        $referenceId = $this->buildReferenceId($notifiable, $payload);

        try {
            Log::info('WhatsAppChannel: sending template', [
                'user_id' => $notifiable->id ?? null,
                'phone' => $toPhone,
                'template' => $template,
                'lang' => $lang,
                'params' => $params,
                'reference_id' => $referenceId,
            ]);

            $resp = $this->wa->sendTemplate(
                $toPhone,
                (string) $template,
                (string) $lang,
                (array) $params
            );

            $messageId = data_get($resp, 'messages.0.id');

            if (!$messageId) {
                Log::warning('WhatsAppChannel: WhatsApp API returned no message id; skipping debit', [
                    'user_id' => $notifiable->id ?? null,
                    'template' => $template,
                    'resp' => $resp,
                ]);
                return;
            }

            $actorUserId = (int) ($notifiable->id ?? 0);
            $desc = 'WhatsApp notification charge: ' . ($template ?? 'template');

            $this->wallets->debitSchoolWalletOrFail(
                $schoolId,
                $cost,
                $actorUserId,
                $desc,
                $referenceId
            );

            Log::info('WhatsAppChannel: sent + debited', [
                'user_id' => $notifiable->id ?? null,
                'school_id' => $schoolId,
                'template' => $template,
                'message_id' => $messageId,
                'reference_id' => $referenceId,
                'cost' => $cost,
            ]);
        } catch (\Throwable $e) {
            Log::error('WhatsAppChannel: send failed; skipping debit', [
                'user_id' => $notifiable->id ?? null,
                'template' => $template,
                'phone' => $toPhone,
                'error' => $e->getMessage(),
            ]);
            return;
        }
    }

    private function priceForTemplate(?string $template): float
    {
        return match ($template) {
            'combined_parent_fees_summary' => 5.0,
            default => 5.0,
        };
    }

    private function buildReferenceId($notifiable, array $payload): string
    {
        $meta = $payload['meta'] ?? [];

        $invoiceId = $meta['invoice_id'] ?? null;
        $invoiceNo = $meta['invoice_no'] ?? null;

        $invKey = $invoiceId
            ? "inv{$invoiceId}"
            : ($invoiceNo ? "invno{$invoiceNo}" : 'inv');

        return 'whatsapp:' . ($payload['template'] ?? 'template')
            . ':u' . ($notifiable->id ?? 0)
            . ':' . $invKey;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '234' . substr($phone, 1);
        }

        if (str_starts_with($phone, '+')) {
            $phone = ltrim($phone, '+');
        }

        return $phone;
    }
}