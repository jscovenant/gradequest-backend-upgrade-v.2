<?php

// app/Services/WhatsAppService.php

namespace App\Services;

use Twilio\Rest\Client;
use App\Models\SchoolSetting;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(
            config('services.twilio.sid'),
            config('services.twilio.auth_token')
        );
    }

    public function sendToParent(int $schoolId, string $toPhone, string $message, ?string $pdfPath = null): bool
    {
        $school = SchoolSetting::find($schoolId);

        if (!$school?->whatsapp_enabled) {
            Log::warning("WhatsApp not enabled for school: {$schoolId}");
            return false;
        }

          // ✅ Check quota before sending
    if (!$this->hasQuota($school)) {
        Log::warning("WhatsApp quota exceeded for school: {$schoolId}");
        return false;
    }

        try {
            $params = [
                'from' => config('services.twilio.from'),
                'body' => $message,
            ];

            if ($pdfPath && file_exists($pdfPath)) {
                $filename  = basename($pdfPath);
                $publicUrl = $this->makePublicAndGetUrl($pdfPath, $filename);
                $params['mediaUrl'] = [$publicUrl];
            }

            $this->client->messages->create(
                "whatsapp:{$this->formatPhone($toPhone)}",
                $params
            );

            $this->incrementUsage($school);

            return true;

        } catch (\Exception $e) {
            Log::error('WhatsApp send failed', [
                'school' => $schoolId,
                'to'     => $toPhone,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function hasQuota(SchoolSetting $school): bool
{
    // Unlimited plan
    if ($school->whatsapp_monthly_limit === -1) return true;

    return $school->whatsapp_messages_sent < $school->whatsapp_monthly_limit;
}

private function incrementUsage(SchoolSetting $school): void
{
    $school->increment('whatsapp_messages_sent');
}

    private function makePublicAndGetUrl(string $filePath, string $filename): string
    {
        $destination = 'public/whatsapp_temp/' . $filename;
        copy($filePath, storage_path('app/' . $destination));
        return url(\Illuminate\Support\Facades\Storage::url($destination));
    }

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '234' . substr($phone, 1);
        }
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }
        return $phone;
    }
}