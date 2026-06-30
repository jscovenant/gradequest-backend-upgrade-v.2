<?php

namespace App\Jobs;

use App\Models\CbtWebhookDelivery;
use App\Models\CbtWebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Delivers a webhook payload to all subscribed endpoints for an event.
 */
class DispatchWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries   = 5;
    public int $timeout = 20;

    public function __construct(
        private int    $schoolId,
        private string $eventType,
        private array  $payload
    ) {}

    public function handle(): void
    {
        $endpoints = CbtWebhookEndpoint::where('school_id', $this->schoolId)
                                       ->where('is_active', true)
                                       ->get()
                                       ->filter(fn($e) => $e->subscribesTo($this->eventType));

        foreach ($endpoints as $endpoint) {
            $this->deliverTo($endpoint);
        }
    }

    private function deliverTo(CbtWebhookEndpoint $endpoint): void
    {
        $idempotencyKey = Str::uuid()->toString();
        $payloadJson    = json_encode($this->payload);
        $signature      = hash_hmac('sha256', $payloadJson, $endpoint->secret);

        $delivery = CbtWebhookDelivery::create([
            'endpoint_id'     => $endpoint->id,
            'event_type'      => $this->eventType,
            'idempotency_key' => $idempotencyKey,
            'payload'         => $this->payload,
            'status'          => 'pending',
        ]);

        try {
            $response = Http::timeout($endpoint->timeout_seconds)
                ->withHeaders([
                    'Content-Type'       => 'application/json',
                    'X-CBT-Event'        => $this->eventType,
                    'X-CBT-Signature'    => "sha256={$signature}",
                    'X-CBT-Delivery-Id'  => $idempotencyKey,
                    'X-CBT-Timestamp'    => now()->timestamp,
                ])
                ->post($endpoint->url, $this->payload);

            $delivery->update([
                'status'       => $response->successful() ? 'delivered' : 'failed',
                'http_status'  => $response->status(),
                'last_response'=> substr($response->body(), 0, 500),
                'attempts'     => $delivery->attempts + 1,
                'delivered_at' => $response->successful() ? now() : null,
            ]);
        } catch (\Exception $e) {
            $delivery->update([
                'status'        => 'failed',
                'last_response' => $e->getMessage(),
                'attempts'      => $delivery->attempts + 1,
                'next_retry_at' => now()->addMinutes(5),
            ]);
        }
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);
    }
}
