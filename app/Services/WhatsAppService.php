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
        $limit = (int) ($school?->whatsapp_monthly_limit ?? 0);
        $used = (int) ($school?->whatsapp_messages_sent ?? 0);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === -1 ? null : max(0, $limit - $used),
            'unlimited' => $limit === -1,
            'has_access' => $limit === -1 || $limit > 0,
        ];
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
        $school->increment('whatsapp_messages_sent');
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
