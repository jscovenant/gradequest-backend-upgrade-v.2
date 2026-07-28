<?php


// app/Jobs/SendCustomNotification.php

namespace App\Jobs;

use App\Models\{AcademicSession, Term, User, SchoolSetting, WhatsAppBroadcastDelivery};
use App\Services\{WhatsAppService, WhatsAppMessageBuilder};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCustomNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        public int    $schoolId,
        public int    $parentId,
        public string $message,
        public ?string $term = null,
        public ?string $session = null
    ) {}

    public function handle(WhatsAppService $whatsapp): void
    {
        $parent = User::where('id', $this->parentId)
            ->where('role', 'Parent')
            ->first();

        $phone = $whatsapp->parentPhone($parent);

        if (! $parent || ! $phone) {
            Log::info("SendCustomNotification: parent {$this->parentId} not found or no WhatsApp/phone number.");
            return;
        }

        $school = SchoolSetting::find($this->schoolId);

        if (!$school) {
            Log::warning("SendCustomNotification: school {$this->schoolId} not found.");
            return;
        }

        $builtMessage = WhatsAppMessageBuilder::custom(
            $this->schoolId,
            trim(($parent->firstname ?? '') . ' ' . ($parent->surname ?? '')) ?: ($parent->name ?? 'Parent'),
            $this->message
        );

        [$term, $session] = $this->broadcastPeriod();
        $messageHash = hash('sha256', $builtMessage);
        $periodKey = WhatsAppBroadcastDelivery::periodKey($term, $session) . '|message:' . substr($messageHash, 0, 16);

        if (WhatsAppBroadcastDelivery::hasActiveSend($this->schoolId, $parent->id, 'custom_notification', $periodKey)) {
            Log::info('SendCustomNotification skipped: same message already sent for this period', [
                'school_id' => $this->schoolId,
                'parent_id' => $parent->id,
                'term' => $term,
                'session' => $session,
            ]);
            return;
        }

        $delivery = WhatsAppBroadcastDelivery::updateOrCreate(
            [
                'school_id' => $this->schoolId,
                'parent_id' => $parent->id,
                'broadcast_type' => 'custom_notification',
                'period_key' => $periodKey,
            ],
            [
                'term' => $term,
                'session' => $session,
                'recipient_phone' => $phone,
                'status' => 'pending',
                'message_hash' => $messageHash,
                'failure_reason' => null,
            ]
        );

        $delivery->update(['status' => 'sending']);

        $sent = $whatsapp->sendToParent(
            $this->schoolId,
            $phone,
            $builtMessage
        );

        $delivery->update($sent ? [
            'status' => 'sent',
            'sent_at' => now(),
            'failure_reason' => null,
        ] : [
            'status' => 'failed',
            'failure_reason' => 'Twilio did not accept the WhatsApp message. Check the application log for the provider error.',
        ]);
    }

    private function broadcastPeriod(): array
    {
        if ($this->term && $this->session) {
            return [$this->term, $this->session];
        }

        $activeTerm = Term::where('school_id', $this->schoolId)
            ->where('status', 'Active')
            ->first();

        $currentSession = AcademicSession::where('school_id', $this->schoolId)
            ->where('is_current', true)
            ->first();

        return [
            $this->term ?: $activeTerm?->name ?: 'Current Term',
            $this->session ?: $currentSession?->name ?: 'Current Session',
        ];
    }
}
