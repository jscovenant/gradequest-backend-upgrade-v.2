<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudClient
{
    public function sendTemplate(string $toPhone, string $templateName, string $lang = 'en', array $bodyParams = []): array
    {
        $version = config('services.whatsapp.version');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $token = config('services.whatsapp.token');

        

        $components = [];
        if (!empty($bodyParams)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    $bodyParams
                ),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizePhone($toPhone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $lang],
                'components' => $components,
            ],    
        ];

        if (!empty($bodyParams)) {
    $payload['template']['components'] = [[
        'type' => 'body',
        'parameters' => array_map(
            fn ($v) => ['type' => 'text', 'text' => (string) $v],
            $bodyParams
        ),
    ]];
}

        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
                    Log::error('WA RUNTIME', [
                'version' => $version,
                'phone_number_id' => $phoneNumberId,
                'url' => $url,
            ]);

        $resp = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        if (!$resp->successful()) {
            throw new \RuntimeException("WhatsApp send failed: {$resp->status()} {$resp->body()}");
        }

        if (empty($phoneNumberId)) {
    throw new \RuntimeException('WhatsApp phone_number_id is empty. Check WHATSAPP_PHONE_NUMBER_ID and config cache.');
}

 

        return $resp->json();
    }

    private function normalizePhone(string $phone): string
    {
        // Expect international digits only e.g. 2348012345678 (no +, no spaces)
        $p = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($p, '0')) {
            // If Nigerians store as 080..., convert: remove leading 0 and prefix 234
            // Adjust if your system supports other countries.
            $p = '234' . substr($p, 1);
        }
        return $p;
    }
}