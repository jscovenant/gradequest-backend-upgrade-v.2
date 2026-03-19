<?php

namespace App\Services;

use App\Models\SchoolWhatsappAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudClient
{
    private string $version;
    private string $globalToken;

    public function __construct()
    {
        $this->version     = config('services.whatsapp.version', 'v23.0');
        $this->globalToken = config('services.whatsapp.token'); // Your .env token only
    }

    public function sendTemplateForSchool(
        int $schoolId,
        string $toPhone,
        string $templateName,
        string $lang = 'en',
        array $bodyParams = []
    ): array {
        $account = SchoolWhatsappAccount::query()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->firstOrFail();

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalizePhone($toPhone),
            'type'              => 'template',
            'template'          => [
                'name'     => $templateName,
                'language' => ['code' => $lang],
            ],
        ];

     if (!empty($bodyParams)) {
    $payload['template']['components'] = [
        [
            'type'       => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => (string) $bodyParams[0]],
            ],
        ],
        [
            // Required for Authentication templates
            'type'    => 'button',
            'sub_type' => 'url',
            'index'   => '0',
            'parameters' => [
                ['type' => 'text', 'text' => (string) $bodyParams[0]],
            ],
        ],
    ];
}

        $url  = "https://graph.facebook.com/{$this->version}/{$account->phone_number_id}/messages";

        $resp = Http::withToken($this->globalToken) // Always your token, never school's
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        if (!$resp->successful()) {
            Log::error('WhatsApp send failed', [
                'school_id' => $schoolId,
                'status'    => $resp->status(),
                'body'      => $resp->body(),
            ]);

            throw new \RuntimeException(
                "WhatsApp send failed: {$resp->status()} {$resp->body()}"
            );
        }

        // Deduct credit from school wallet after confirmed send
        $this->deductSchoolCredit($schoolId);

        return $resp->json();
    }

    private function deductSchoolCredit(int $schoolId): void
    {
        // Hook into your wallet/credit service here
        // e.g. SchoolWalletService::deduct($schoolId, 1);
    }

    public function normalizePhone(string $phone): string
    {
        $p = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($p, '0')) {
            $p = '234' . substr($p, 1);
        }

        return $p;
    }
}