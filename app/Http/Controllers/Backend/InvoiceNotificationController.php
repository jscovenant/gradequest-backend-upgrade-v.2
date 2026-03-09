<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoiceNotificationController extends Controller
{
    public function unread()
    {
        $userId = Auth::id();

        $notes = DB::table('combined_fee_reminder_logs')
            ->where('parent_id', $userId)
            ->where('is_read', false)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->take(10)
            ->get();

        return response()->json([
            'data' => collect($notes)->map(fn ($n) => $this->shape((object) $n))->values(),
        ]);
    }

    public function index()
    {
        $userId = Auth::id();

        $notes = DB::table('combined_fee_reminder_logs')
            ->where('parent_id', $userId)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($notes->items())->map(fn ($n) => $this->shape((object) $n))->values(),
            'meta' => [
                'current_page' => $notes->currentPage(),
                'last_page' => $notes->lastPage(),
                'per_page' => $notes->perPage(),
                'total' => $notes->total(),
            ],
        ]);
    }

    public function show($id)
    {
        $userId = Auth::id();

        $note = DB::table('combined_fee_reminder_logs')
            ->where('parent_id', $userId)
            ->where('id', $id)
            ->first();

        abort_unless($note, 404);

        return response()->json([
            'data' => $this->shape((object) $note),
        ]);
    }

    public function markRead($id)
    {
        $userId = Auth::id();

        $note = DB::table('combined_fee_reminder_logs')
            ->where('parent_id', $userId)
            ->where('id', $id)
            ->first();

        abort_unless($note, 404);

        if (!(bool) $note->is_read) {
            DB::table('combined_fee_reminder_logs')
                ->where('id', $id)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    public function bankDetails($id)
    {
        $userId = Auth::id();

        $note = DB::table('combined_fee_reminder_logs')
            ->where('parent_id', $userId)
            ->where('id', $id)
            ->first();

        abort_unless($note, 404, 'Notification not found.');

        $schoolId = (int) ($note->school_id ?? 0);
        abort_if($schoolId <= 0, 404, 'School not found for this notification.');

        $gateway = DB::table('payment_gateways')
            ->where('school_id', $schoolId)
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->select([
                'bank_name',
                'account_name',
                'account_number',
            ])
            ->first();

        abort_unless($gateway, 404, 'No active school bank details found.');

        return response()->json([
            'data' => [
                'school_id' => $schoolId,
                'bank_name' => $gateway->bank_name,
                'account_name' => $gateway->account_name,
                'account_number' => $gateway->account_number,
                'instruction' => 'After making payment, please upload your payment receipt so the school can verify and update your payment record.',
            ],
        ]);
    }

    private function shape(object $n): array
    {
        $payload = [];
        if (!empty($n->payload)) {
            $decoded = json_decode($n->payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $children = collect($payload['children'] ?? [])->map(function ($child) {
            return [
                'student_id' => $child['student_id'] ?? null,
                'student_name' => $child['student_name'] ?? 'Student',
                'items' => collect($child['items'] ?? [])->map(function ($item) {
                    return [
                        'fee_title' => $item['fee_title'] ?? 'Fee',
                        'amount' => (float) ($item['amount'] ?? 0),
                        'paid' => (float) ($item['paid'] ?? 0),
                        'balance' => (float) ($item['balance'] ?? 0),
                        'term_id' => $item['term_id'] ?? null,
                        'session_id' => $item['session_id'] ?? null,
                    ];
                })->values(),
            ];
        })->values();

        $studentCount = $children->count();
        $itemCount = $children->sum(fn ($child) => collect($child['items'] ?? [])->count());
        $paymentUrl = $payload['payment_url'] ?? url('/');

        return [
            'id' => $n->id,
            'type' => 'fee_reminder',
            'summary_type' => $payload['summary_type'] ?? 'combined_parent_fees',
            'message' => $this->buildMessage($payload, $studentCount, $itemCount),
            'time' => $n->sent_at ? \Carbon\Carbon::parse($n->sent_at)->diffForHumans() : null,
            'created_at' => $n->created_at ? \Carbon\Carbon::parse($n->created_at)->toDateTimeString() : null,
            'sent_at' => $n->sent_at ? \Carbon\Carbon::parse($n->sent_at)->toDateTimeString() : null,
            'read_at' => $n->read_at ? \Carbon\Carbon::parse($n->read_at)->toDateTimeString() : null,
            'is_read' => (bool) ($n->is_read ?? false),

            'summary' => [
                'school_id' => (int) ($n->school_id ?? 0),
                'parent_id' => (int) ($n->parent_id ?? 0),
                'parent_name' => $payload['parent_name'] ?? 'Parent/Guardian',
                'student_count' => $studentCount,
                'item_count' => $itemCount,
                'total_amount' => (float) ($payload['total_amount'] ?? 0),
                'total_paid' => (float) ($payload['total_paid'] ?? 0),
                'total_balance' => (float) ($payload['total_balance'] ?? 0),
                'due_date' => $payload['due_date'] ?? null,
                'payment_url' => $paymentUrl,
                'children' => $children,
            ],
        ];
    }

    private function buildMessage(array $payload, int $studentCount, int $itemCount): string
    {
        $balance = number_format((float) ($payload['total_balance'] ?? 0), 2);

        return "Outstanding fees reminder for {$studentCount} student(s), {$itemCount} fee item(s). Total balance: ₦{$balance}.";
    }
}