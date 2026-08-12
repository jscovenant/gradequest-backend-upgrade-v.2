<?php

namespace App\Services;

use App\Models\SchoolSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Twilio\Rest\Client;

class WhatsAppService
{
    protected ?Client $client = null;

    public function __construct(private readonly SubscriptionWhatsappCreditService $credits)
    {
    }

    public function isConfigured(): bool
    {
        return filled(config('services.twilio.sid'))
            && filled(config('services.twilio.auth_token'))
            && filled(config('services.twilio.from'));
    }

    public function configurationStatus(): array
    {
        return [
            'sid' => filled(config('services.twilio.sid')),
            'auth_token' => filled(config('services.twilio.auth_token')),
            'from' => filled(config('services.twilio.from')),
            'from_number' => config('services.twilio.from'),
            'ready' => $this->isConfigured(),
        ];
    }

    public function sendToParent(int $schoolId, string $toPhone, string $message, ?string $pdfPath = null): bool
    {
        $school = SchoolSetting::find($schoolId);

        if (! $school?->whatsapp_enabled) {
            Log::warning('WhatsApp skipped: disabled for school', ['school_id' => $schoolId]);
            return false;
        }

        if (! $this->isConfigured()) {
            Log::error('WhatsApp skipped: Twilio configuration is incomplete', $this->configurationStatus());
            return false;
        }

        if (! $this->hasQuota($school)) {
            Log::warning('WhatsApp skipped: monthly quota exceeded', ['school_id' => $schoolId]);
            return false;
        }

        try {
            $params = [
                'from' => config('services.twilio.from'),
                'body' => $message,
            ];

            if ($pdfPath && file_exists($pdfPath)) {
                $publicUrl = $this->makePublicAndGetUrl($pdfPath, basename($pdfPath));

                if ($publicUrl) {
                    $params['mediaUrl'] = [$publicUrl];
                }
            }

            $sent = $this->client()->messages->create(
                'whatsapp:' . $this->formatPhone($toPhone),
                $params
            );

            $this->incrementUsage($school);

            Log::info('WhatsApp sent successfully', [
                'school_id' => $schoolId,
                'to' => $this->maskPhone($toPhone),
                'sid' => $sent->sid ?? null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('WhatsApp send failed', [
                'school_id' => $schoolId,
                'to' => $this->maskPhone($toPhone),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendSystemMessage(string $toPhone, string $message): bool
    {
        if (! $this->isConfigured()) {
            Log::error('WhatsApp system test skipped: Twilio configuration is incomplete', $this->configurationStatus());
            return false;
        }

        try {
            $sent = $this->client()->messages->create(
                'whatsapp:' . $this->formatPhone($toPhone),
                [
                    'from' => config('services.twilio.from'),
                    'body' => $message,
                ]
            );

            Log::info('WhatsApp system test sent successfully', [
                'to' => $this->maskPhone($toPhone),
                'sid' => $sent->sid ?? null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('WhatsApp system test failed', [
                'to' => $this->maskPhone($toPhone),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (! $phone) {
            throw new RuntimeException('Recipient WhatsApp number is empty.');
        }

        if (str_starts_with($phone, '0')) {
            $phone = '234' . substr($phone, 1);
        }

        if (! str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    public function parentPhone(?object $parent): ?string
    {
        if (! $parent) {
            return null;
        }

        return $parent->whatsapp_number
            ?: $parent->whatsapp_no
            ?: $parent->phone
            ?: null;
    }

    public function quotaSummary(?SchoolSetting $school): array
    {
        if (! $school) {
            return ['limit' => 0, 'used' => 0, 'remaining' => 0, 'unlimited' => false, 'has_access' => false];
        }

        try {
            $summary = $this->credits->getCreditSummary((int) $school->id);

            return [
                'limit' => (int) $summary['allocated_credits'],
                'used' => (int) $summary['used_credits'],
                'remaining' => (int) $summary['remaining_credits'],
                'unlimited' => false,
                'has_access' => true,
            ];
        } catch (\Throwable) {
            return ['limit' => 0, 'used' => 0, 'remaining' => 0, 'unlimited' => false, 'has_access' => false];
        }
    }

    private function hasQuota(SchoolSetting $school): bool
    {
        $summary = $this->quotaSummary($school);

        if ($summary['unlimited']) {
            return true;
        }

        return $summary['has_access'] && $summary['remaining'] > 0;
    }

    private function incrementUsage(SchoolSetting $school): void
    {
        $this->credits->consumeCredits((int) $school->id);
    }

    private function makePublicAndGetUrl(string $filePath, string $filename): ?string
    {
        if (! file_exists($filePath)) {
            return null;
        }

        $destination = 'public/whatsapp_temp/' . $filename;
        copy($filePath, storage_path('app/' . $destination));

        return url(Storage::url($destination));
    }

    private function client(): Client
    {
        if (! $this->client) {
            $this->client = new Client(
                config('services.twilio.sid'),
                config('services.twilio.auth_token')
            );
        }

        return $this->client;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) <= 4) {
            return '****';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }
}
