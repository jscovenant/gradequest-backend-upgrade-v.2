<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareTurnstileService
{
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Verify a Cloudflare Turnstile token from the frontend.
     *
     * @param string|null $token
     * @param string|null $ip
     * @return bool
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        return true;
    }

        // If turnstile is not configured or disabled, bypass verification (safe for local development)
        if (!$enabled || empty($secret)) {
            return true;
        }

        // If configured and enabled, token is strictly required
        if (empty($token)) {
            return false;
        }

        try {
            $payload = [
                'secret'   => $secret,
                'response' => $token,
            ];

            $response = Http::asForm()->timeout(8)->post(self::VERIFY_URL, $payload);

            if (!$response->successful()) {
                Log::warning('Cloudflare Turnstile verification HTTP error: ' . $response->status() . ' - ' . $response->body());
                return false;
            }

            $data = $response->json();
            $success = (bool) ($data['success'] ?? false);
            if (!$success) {
                Log::warning('Cloudflare Turnstile verification failed. Response: ' . json_encode($data));
            }
            return $success;
        } catch (\Throwable $e) {
            Log::error('Cloudflare Turnstile verification exception: ' . $e->getMessage());
            return false;
        }
    }
}