<?php

namespace App\Jobs;

use App\Models\Average;
use App\Models\ParentStudent;
use App\Models\ResultPin;
use App\Models\User;
use App\Models\WhatsAppBroadcastDelivery;
use App\Services\WhatsAppMessageBuilder;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendResultNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $studentId,
        public int $classId,
        public string $term,
        public string $session
    ) {
    }

    public function handle(WhatsAppService $whatsapp): void
    {
        $student = User::find($this->studentId);

        if (! $student) {
            Log::info('SendResultNotification skipped: student not found', [
                'student_id' => $this->studentId,
            ]);
            return;
        }

        $parentLinks = ParentStudent::where('student_id', $student->id)
            ->where('school_id', $student->school_id)
            ->get();

        if ($parentLinks->isEmpty()) {
            Log::info('SendResultNotification skipped: no parents linked', [
                'student_id' => $this->studentId,
            ]);
            return;
        }

        $average = Average::where('user_id', $this->studentId)
            ->where('class_id', $this->classId)
            ->where('term', $this->term)
            ->where('session', $this->session)
            ->first();

        foreach ($parentLinks as $link) {
            $parent = User::where('id', $link->parent_id)
                ->whereRaw('LOWER(role) = ?', ['parent'])
                ->first();

            $phone = $this->verifiedWhatsappNumber($parent);

            if (! $parent || ! $phone) {
                Log::info('SendResultNotification skipped: parent WhatsApp is not verified', [
                    'student_id' => $student->id,
                    'parent_id' => $parent?->id,
                ]);
                continue;
            }

            $periodKey = WhatsAppBroadcastDelivery::periodKey(
                $this->term,
                $this->session,
                $this->classId,
                $student->id
            );

            if (WhatsAppBroadcastDelivery::hasActiveSend((int) $student->school_id, $parent->id, 'result_notification', $periodKey)) {
                Log::info('SendResultNotification skipped: already sent for this student result period', [
                    'school_id' => $student->school_id,
                    'student_id' => $student->id,
                    'parent_id' => $parent->id,
                    'term' => $this->term,
                    'session' => $this->session,
                ]);
                continue;
            }

            $pin = $this->createResultPin($student);
            $resultLink = $this->publicResultLink($student, $pin);
            $message = WhatsAppMessageBuilder::result($student, $average, $parent, $pin, $resultLink);

            $delivery = WhatsAppBroadcastDelivery::updateOrCreate(
                [
                    'school_id' => (int) $student->school_id,
                    'parent_id' => $parent->id,
                    'broadcast_type' => 'result_notification',
                    'period_key' => $periodKey,
                ],
                [
                    'student_id' => $student->id,
                    'class_id' => $this->classId,
                    'term' => $this->term,
                    'session' => $this->session,
                    'recipient_phone' => $phone,
                    'status' => 'pending',
                    'message_hash' => hash('sha256', $message),
                    'failure_reason' => null,
                ]
            );

            $delivery->update(['status' => 'sending']);

            $sent = $whatsapp->sendToParent(
                (int) $student->school_id,
                $phone,
                $message
            );

            $delivery->update($sent ? [
                'status' => 'sent',
                'sent_at' => now(),
                'failure_reason' => null,
            ] : [
                'status' => 'failed',
                'failure_reason' => 'Twilio did not accept the WhatsApp message. Check the application log for the provider error.',
            ]);

            sleep(1);
        }
    }

    private function createResultPin(User $student): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        } while (ResultPin::where('pin', $pin)->exists());

        ResultPin::create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'pin' => $pin,
            'term' => $this->term,
            'session' => $this->session,
            'max_uses' => 5,
            'used_count' => 0,
            'is_active' => true,
            'expires_at' => now()->addDays(30),
        ]);

        return $pin;
    }

    private function publicResultLink(User $student, string $pin): string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $query = http_build_query([
            'reg_no' => $student->reg_no,
            'pin' => $pin,
        ]);

        return "{$baseUrl}/check-result?{$query}";
    }

    private function verifiedWhatsappNumber(?User $parent): ?string
    {
        if (! $parent || ! $parent->whatsapp_verified_at) {
            return null;
        }

        return $parent->whatsapp_number ?: $parent->whatsapp_no ?: null;
    }
}
