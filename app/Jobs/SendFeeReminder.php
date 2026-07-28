<?php

namespace App\Jobs;

use App\Models\ParentStudent;
use App\Models\SchoolBankAccount;
use App\Models\SchoolBillingSetting;
use App\Models\AcademicSession;
use App\Models\StudentFee;
use App\Models\Term;
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

class SendFeeReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public int $schoolId, public int $parentId)
    {
    }

    public function handle(WhatsAppService $whatsapp): void
    {
        $parent = User::where('id', $this->parentId)
            ->where('school_id', $this->schoolId)
            ->whereRaw('LOWER(role) = ?', ['parent'])
            ->first();

        if (! $parent) {
            Log::info('SendFeeReminder skipped: parent not found', [
                'school_id' => $this->schoolId,
                'parent_id' => $this->parentId,
            ]);
            return;
        }

        $studentIds = ParentStudent::where('school_id', $this->schoolId)
            ->where('parent_id', $parent->id)
            ->pluck('student_id')
            ->unique()
            ->values();

        if ($studentIds->isEmpty()) {
            Log::info('SendFeeReminder skipped: parent has no linked students', [
                'school_id' => $this->schoolId,
                'parent_id' => $parent->id,
            ]);
            return;
        }

        $fees = StudentFee::with(['student', 'feeType', 'term', 'session'])
            ->where('school_id', $this->schoolId)
            ->whereIn('student_id', $studentIds)
            ->where('balance', '>', 0)
            ->orderBy('student_id')
            ->orderBy('session_id')
            ->orderBy('term_id')
            ->orderBy('id')
            ->get();

        if ($fees->isEmpty()) {
            Log::info('SendFeeReminder skipped: no outstanding fee records', [
                'school_id' => $this->schoolId,
                'parent_id' => $parent->id,
            ]);
            return;
        }

        $phone = $whatsapp->parentPhone($parent);

        if (! $phone) {
            Log::info('SendFeeReminder skipped: parent has no WhatsApp/phone number', [
                'school_id' => $this->schoolId,
                'parent_id' => $parent->id,
            ]);
            return;
        }

        [$term, $session] = $this->broadcastPeriod($fees);
        $periodKey = WhatsAppBroadcastDelivery::periodKey($term, $session);

        if (WhatsAppBroadcastDelivery::hasActiveSend($this->schoolId, $parent->id, 'fee_reminder', $periodKey)) {
            Log::info('SendFeeReminder skipped: already sent for this period', [
                'school_id' => $this->schoolId,
                'parent_id' => $parent->id,
                'term' => $term,
                'session' => $session,
            ]);
            return;
        }

        $message = WhatsAppMessageBuilder::feeReminder(
            $fees,
            $parent,
            $this->onlinePaymentLinks($fees),
            $this->bankAccounts()
        );

        $delivery = WhatsAppBroadcastDelivery::updateOrCreate(
            [
                'school_id' => $this->schoolId,
                'parent_id' => $parent->id,
                'broadcast_type' => 'fee_reminder',
                'period_key' => $periodKey,
            ],
            [
                'term' => $term,
                'session' => $session,
                'recipient_phone' => $phone,
                'status' => 'pending',
                'message_hash' => hash('sha256', $message),
                'failure_reason' => null,
            ]
        );

        $delivery->update(['status' => 'sending']);

        $sent = $whatsapp->sendToParent(
            $this->schoolId,
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
    }

    private function onlinePaymentLinks($fees): array
    {
        $billing = SchoolBillingSetting::where('school_id', $this->schoolId)->first();

        if (($billing?->payment_mode ?? 'offline') !== 'online') {
            return [];
        }

        $hasOnlineAccount = SchoolBankAccount::where('school_id', $this->schoolId)
            ->where('is_active', true)
            ->where('online_payment_enabled', true)
            ->whereNotNull('paystack_subaccount_code')
            ->exists();

        if (! $hasOnlineAccount) {
            return [];
        }

        $admin = User::where('school_id', $this->schoolId)
            ->whereRaw('LOWER(role) = ?', ['admin'])
            ->whereNotNull('reg_no')
            ->orderBy('id')
            ->first();

        if (! $admin?->reg_no) {
            return [];
        }

        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $links = [];

        foreach ($fees->groupBy('student_id') as $studentId => $studentFees) {
            $student = $studentFees->first()?->student;

            if (! $student?->reg_no) {
                continue;
            }

            $query = http_build_query([
                'school_code' => $admin->reg_no,
                'student_reg_no' => $student->reg_no,
                'amount' => number_format((float) $studentFees->sum('balance'), 2, '.', ''),
            ]);

            $links[(int) $studentId] = "{$baseUrl}/pay-school-fee?{$query}";
        }

        return $links;
    }

    private function bankAccounts()
    {
        return SchoolBankAccount::where('school_id', $this->schoolId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['bank_name', 'account_name', 'account_number', 'currency']);
    }

    private function broadcastPeriod($fees): array
    {
        $activeTerm = Term::where('school_id', $this->schoolId)
            ->where('status', 'Active')
            ->first();

        $currentSession = AcademicSession::where('school_id', $this->schoolId)
            ->where('is_current', true)
            ->first();

        $firstFee = $fees->first();

        return [
            $activeTerm?->name ?: $firstFee?->term?->name ?: 'Current Term',
            $currentSession?->name ?: $firstFee?->session?->name ?: 'Current Session',
        ];
    }
}
