<?php

namespace App\Http\Controllers\Backend;
 
use App\Http\Controllers\Controller;
use App\Jobs\{SendResultNotification, SendFeeReminder, SendCustomNotification};
use App\Models\{Average, Invoice, User};
use Illuminate\Http\Request;
 
class WhatsAppBroadcastController extends Controller
{
    // POST /whatsapp/broadcast/results
    public function broadcastResults(Request $request)
    {
        $request->validate([
            'term'     => 'required|in:First Term,Second Term,Third Term',
            'session'  => 'required|string|max:20',
            'class_id' => 'required|integer',
        ]);
 
        $schoolId = $request->user()->school_id;
 
        $studentIds = Average::where('class_id', $request->class_id)
            ->where('term', $request->term)
            ->where('session', $request->session)
            ->whereHas('user', fn($q) => $q->where('school_id', $schoolId))
            ->pluck('user_id');
 
        if ($studentIds->isEmpty()) {
            return response()->json([
                'message' => 'No students found with results for the selected term, session and class.',
            ], 404);
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
 
        $invoices = Invoice::where('school_id', $schoolId)
            ->where('status', 'unpaid')
            ->where('due_date', '<=', now())
            ->whereHas('student.parent', fn($q) => $q->whereNotNull('whatsapp_number'))
            ->get();
 
        if ($invoices->isEmpty()) {
            return response()->json([
                'message' => 'No overdue unpaid invoices found.',
            ], 404);
        }
 
        foreach ($invoices as $invoice) {
            SendFeeReminder::dispatch($invoice->id);
        }
 
        return response()->json([
            'message' => "{$invoices->count()} fee reminder(s) queued for WhatsApp delivery.",
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
            ->whereNotNull('whatsapp_number')
            ->get();
 
        if ($parents->isEmpty()) {
            return response()->json([
                'message' => 'No parents with WhatsApp numbers found.',
            ], 404);
        }
 
        $loop = 0;
        foreach ($parents as $parent) {
            SendCustomNotification::dispatch(
                $schoolId,
                $parent->id,
                $request->message
            )->delay(now()->addSeconds($loop++ * 2));
        }
 
        return response()->json([
            'message' => "{$parents->count()} custom message(s) queued for WhatsApp delivery.",
        ]);
    }
}
 