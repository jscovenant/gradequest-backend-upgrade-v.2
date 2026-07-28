<?php

namespace App\Http\Controllers\Backend;
 
use App\Http\Controllers\Controller;
use App\Jobs\{SendResultNotification, SendFeeReminder, SendCustomNotification};
use App\Models\{AcademicSession, Average, ParentStudent, StudentFee, Term, User, WhatsAppBroadcastDelivery};
use App\Services\SubscriptionGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
 
class WhatsAppBroadcastController extends Controller
{
    public function __construct(private SubscriptionGate $subscriptionGate)
    {
    }

    // POST /whatsapp/broadcast/results
    public function broadcastResults(Request $request)
    {
        $request->validate([
            'term'     => 'required|in:First Term,Second Term,Third Term',
            'session'  => 'required|string|max:20',
            'class_id' => 'required|integer',
        ]);
 
        $schoolId = $request->user()->school_id;
 
        $studentIds = DB::table('result_batches as b')
            ->join('student_results_v2 as sr', 'sr.batch_id', '=', 'b.id')
            ->join('users as u', 'u.id', '=', 'sr.user_id')
            ->where('b.school_id', $schoolId)
            ->where('b.class_id', $request->class_id)
            ->where('b.term', $request->term)
            ->where('b.session', $request->session)
            ->where('b.status', 'published')
            ->whereRaw('LOWER(u.role) = ?', ['student'])
            ->whereColumn('u.level_id', 'b.class_id')
            ->pluck('sr.user_id')
            ->merge(
                Average::where('class_id', $request->class_id)
                    ->where('term', $request->term)
                    ->where('session', $request->session)
                    ->whereHas('user', fn ($q) => $q->where('school_id', $schoolId))
                    ->pluck('user_id')
            )
            ->unique()
            ->values();
 
        if ($studentIds->isEmpty()) {
            $batch = DB::table('result_batches')
                ->where('school_id', $schoolId)
                ->where('class_id', $request->class_id)
                ->where('term', $request->term)
                ->where('session', $request->session)
                ->first();

            if ($batch) {
                $resultCount = DB::table('student_results_v2 as sr')
                    ->join('users as u', 'u.id', '=', 'sr.user_id')
                    ->where('sr.batch_id', $batch->id)
                    ->where('u.school_id', $schoolId)
                    ->where('u.role', 'Student')
                    ->where('u.level_id', $batch->class_id)
                    ->count();

                if ($resultCount > 0 && ($batch->status ?? null) !== 'published') {
                    return response()->json([
                        'message' => "Results exist for the selected class, term and session, but they are not published yet. Current status: {$batch->status}. Publish the result before sending WhatsApp result links.",
                        'status' => 'not_published',
                        'batch' => [
                            'id' => $batch->id,
                            'status' => $batch->status,
                            'result_count' => $resultCount,
                        ],
                    ], 422);
                }
            }

            return response()->json([
                'message' => 'No students found with results for the selected term, session and class.',
            ], 404);
        }

        $studentIds = $studentIds->filter(function ($studentId) use ($schoolId, $request) {
            $parentIds = ParentStudent::where('school_id', $schoolId)
                ->where('student_id', $studentId)
                ->pluck('parent_id')
                ->unique();

            if ($parentIds->isEmpty()) {
                return false;
            }

            $periodKey = WhatsAppBroadcastDelivery::periodKey(
                $request->term,
                $request->session,
                (int) $request->class_id,
                (int) $studentId
            );

            return $parentIds->contains(function ($parentId) use ($schoolId, $periodKey) {
                return ! WhatsAppBroadcastDelivery::hasActiveSend(
                    (int) $schoolId,
                    (int) $parentId,
                    'result_notification',
                    $periodKey
                );
            });
        })->values();

        if ($studentIds->isEmpty()) {
            return response()->json([
                'message' => 'Result WhatsApp notification has already been sent for the selected students in this term and session.',
            ], 200);
        }

        $usageCheck = $this->inspectWhatsappUsage($request, $studentIds->count());
        if ($usageCheck) {
            return $usageCheck;
        }
 
        $loop = 0;
        foreach ($studentIds as $studentId) {
            SendResultNotification::dispatch(
                $studentId,
                $request->class_id,
                $request->term,
                $request->session
            )->delay(now()->addSeconds($loop++ * 3));
        }
 
        return response()->json([
            'message' => "{$studentIds->count()} result notification(s) queued for WhatsApp delivery.",
        ]);
    }
 
    // POST /whatsapp/broadcast/fee-reminders
    public function broadcastFeeReminders(Request $request)
    {
        $schoolId = $request->user()->school_id;
 
        $fees = StudentFee::where('school_id', $schoolId)
            ->where('balance', '>', 0)
            ->whereHas('student')
            ->get();
 
        if ($fees->isEmpty()) {
            return response()->json([
                'message' => 'No unpaid student fee records found.',
            ], 404);
        }

        $studentIds = $fees->pluck('student_id')->unique()->values();
        $parentIds = ParentStudent::where('school_id', $schoolId)
            ->whereIn('student_id', $studentIds)
            ->pluck('parent_id')
            ->unique()
            ->values();

        if ($parentIds->isEmpty()) {
            return response()->json([
                'message' => 'No linked parent accounts were found for students with unpaid fee records.',
            ], 404);
        }

        [$termName, $sessionName] = $this->currentBroadcastPeriod($schoolId, $fees);
        $periodKey = WhatsAppBroadcastDelivery::periodKey($termName, $sessionName);

        $parentIds = $parentIds->filter(function ($parentId) use ($schoolId, $periodKey) {
            return ! WhatsAppBroadcastDelivery::hasActiveSend(
                (int) $schoolId,
                (int) $parentId,
                'fee_reminder',
                $periodKey
            );
        })->values();

        if ($parentIds->isEmpty()) {
            return response()->json([
                'message' => 'Fee reminder has already been sent to all eligible parents for the current term and session.',
            ], 200);
        }

        $usageCheck = $this->inspectWhatsappUsage($request, $parentIds->count());
        if ($usageCheck) {
            return $usageCheck;
        }
 
        foreach ($parentIds as $parentId) {
            SendFeeReminder::dispatch((int) $schoolId, (int) $parentId);
        }
 
        return response()->json([
            'message' => "{$parentIds->count()} parent fee reminder(s) queued for WhatsApp delivery.",
        ]);
    }
 
    // POST /whatsapp/broadcast/custom
    public function customBroadcast(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
        ]);
 
        $schoolId = $request->user()->school_id;
 
        // Get all unique parents linked to this school
        $parentIds = \App\Models\ParentStudent::where('school_id', $schoolId)
            ->pluck('parent_id')
            ->unique();
 
        $parents = User::whereIn('id', $parentIds)
            ->where('role', 'Parent')
            ->where(function ($query) {
                $query->whereNotNull('whatsapp_number')
                    ->orWhereNotNull('whatsapp_no')
                    ->orWhereNotNull('phone');
            })
            ->get();
 
        if ($parents->isEmpty()) {
            return response()->json([
                'message' => 'No parents with WhatsApp numbers found.',
            ], 404);
        }

        [$termName, $sessionName] = $this->currentBroadcastPeriod($schoolId);
        $messagePreview = trim($request->message);
        $messageHash = hash('sha256', $messagePreview);
        $periodKey = WhatsAppBroadcastDelivery::periodKey($termName, $sessionName) . '|message:' . substr($messageHash, 0, 16);

        $parents = $parents->filter(function ($parent) use ($schoolId, $periodKey) {
            return ! WhatsAppBroadcastDelivery::hasActiveSend(
                (int) $schoolId,
                (int) $parent->id,
                'custom_notification',
                $periodKey
            );
        })->values();

        if ($parents->isEmpty()) {
            return response()->json([
                'message' => 'This WhatsApp message has already been sent to all eligible parents for the current term and session.',
            ], 200);
        }

        $usageCheck = $this->inspectWhatsappUsage($request, $parents->count());
        if ($usageCheck) {
            return $usageCheck;
        }
 
        $loop = 0;
        foreach ($parents as $parent) {
            SendCustomNotification::dispatch(
                $schoolId,
                $parent->id,
                $request->message,
                $termName,
                $sessionName
            )->delay(now()->addSeconds($loop++ * 2));
        }
 
        return response()->json([
            'message' => "{$parents->count()} custom message(s) queued for WhatsApp delivery.",
        ]);
    }

    private function inspectWhatsappUsage(Request $request, int $recipientCount)
    {
        $result = $this->subscriptionGate->inspect(
            $request->user(),
            'whatsapp_notifications',
            'usage',
            $recipientCount
        );

        if (! $result['allowed']) {
            return response()->json([
                'message' => $result['message'],
                'reason' => $result['reason'],
                'subscription' => [
                    'feature_key' => 'whatsapp_notifications',
                    'limit_key' => 'usage',
                    'limit' => $result['limit'] ?? null,
                    'used' => $result['used'] ?? null,
                    'requested' => $recipientCount,
                ],
            ], $result['status'] ?? 403);
        }

        $this->subscriptionGate->recordUsage($request->user(), 'whatsapp_notifications', $recipientCount);

        return null;
    }

    private function currentBroadcastPeriod(int $schoolId, $fees = null): array
    {
        $activeTerm = Term::where('school_id', $schoolId)
            ->where('status', 'Active')
            ->first();

        $currentSession = AcademicSession::where('school_id', $schoolId)
            ->where('is_current', true)
            ->first();

        $firstFee = $fees?->first();

        return [
            $activeTerm?->name ?: $firstFee?->term?->name ?: 'Current Term',
            $currentSession?->name ?: $firstFee?->session?->name ?: 'Current Session',
        ];
    }
}
 
