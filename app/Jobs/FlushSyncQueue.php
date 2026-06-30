<?php

namespace App\Jobs;


use App\Models\CbtAccessKey;
use App\Models\CbtSyncQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute via scheduler.
 * Flushes pending sync items to the CBT platform when internet is available.
 */
class FlushSyncQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries   = 1;
    public int $timeout = 120;

    public function handle(): void
    {
        if (!$this->hasInternetConnection()) {
            Log::info('CBT FlushSyncQueue: No internet connection. Skipping.');
            return;
        }

        // Process up to 50 items per run to avoid timeout
        $items = CbtSyncQueue::pending()->limit(50)->get();

        if ($items->isEmpty()) return;

        Log::info("CBT FlushSyncQueue: Processing {$items->count()} items.");

        foreach ($items as $item) {
            $this->processItem($item);
        }
    }

    private function processItem(CbtSyncQueue $item): void
    {
        $item->markProcessing();

        // Get the school's CBT token
        $key = CbtAccessKey::where('school_id', $item->school_id)->connected()->first();

        if (!$key) {
            $item->markFailed('School has no active CBT connection.');
            return;
        }

        try {
            $baseUrl  = config('cbt.platform_url');
            $payload  = json_encode($item->payload);
            $secret   = config('cbt.signing_secret');
            $sig      = hash_hmac('sha256', $payload, $secret);

            $response = Http::timeout(10)
                ->withToken($key->cbt_tenant_token)
                ->withHeaders([
                    'X-Signature'        => "sha256={$sig}",
                    'X-Timestamp'        => now()->timestamp,
                    'X-Idempotency-Key'  => $item->idempotency_key,
                ])
                ->{strtolower($item->method)}(
                    "{$baseUrl}{$item->endpoint}",
                    $item->payload
                );

            if ($response->successful()) {
                $item->markSynced();
                $key->update(['last_used_at' => now()]);
            } else {
                $item->markFailed(
                    $response->json('message') ?? "HTTP {$response->status()}",
                    $response->status()
                );
            }
        } catch (\Exception $e) {
            $item->markFailed($e->getMessage());
            Log::error("CBT sync failed for item {$item->id}: {$e->getMessage()}");
        }
    }

    private function hasInternetConnection(): bool
    {
        try {
            $response = Http::timeout(3)->get(config('cbt.platform_url') . '/ping');
            return $response->ok();
        } catch (\Exception) {
            return false;
        }
    }
}
