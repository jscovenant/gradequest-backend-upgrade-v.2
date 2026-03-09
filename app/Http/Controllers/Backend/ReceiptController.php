<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Storage;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\DB;

class ReceiptController extends Controller
{
 
   

public function uploadReceipts(Request $request)
{
    $request->validate([
        'reg_no' => 'required|string|max:50',
        'payment_method' => 'required|in:online,cash',
        'term_id' => 'nullable|exists:terms,id',
        'session_id' => 'nullable|exists:academic_sessions,id',
        'amount' => 'nullable|numeric',
        'receipts.*' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
    ]);

    $schoolId = Auth::user()->school_id;
    $regNo = trim($request->reg_no);

    // ✅ Resolve student_id by reg_no + school
    $student = DB::table('users')
        ->where('school_id', $schoolId)
        ->where('reg_no', $regNo)
        ->select('id')
        ->first();

    if (!$student) {
        return response()->json(['message' => 'Student not found for this Reg No.'], 404);
    }

    $destinationPath = public_path('uploads/receipts');
    if (!file_exists($destinationPath)) {
        mkdir($destinationPath, 0775, true);
    }

    $uploadedPaths = [];
    if ($request->hasFile('receipts')) {
        foreach ($request->file('receipts') as $file) {
            $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($destinationPath, $fileName);
            $uploadedPaths[] = 'uploads/receipts/' . $fileName;
        }
    }

    $receipt = PaymentReceipt::create([
        'student_id' => (int)$student->id,
        'school_id' => $schoolId,
        'payment_method' => $request->payment_method,
        'receipt_path' => json_encode($uploadedPaths),
        'status' => 'pending',
    ]);

    return response()->json([
        'message' => 'Receipts uploaded successfully',
        'data' => [
            'id' => $receipt->id,
            'files' => array_map(fn($p) => asset($p), $uploadedPaths),
            'status' => $receipt->status,
        ],
    ]);
}

    /**
     * List receipts for a student (Parent view or Student view).
     * Query: ?student_id=744
     */


public function myReceipts(Request $request)
{
    $request->validate([
        'reg_no' => 'required|string|max:50',
    ]);

    $user = Auth::user();
    $schoolId = $user->school_id;
    $regNo = trim($request->reg_no);

    // ✅ Resolve student by reg_no within this school
    $student = DB::table('users')
        ->where('school_id', $schoolId)
        ->where('reg_no', $regNo)
        ->select('id', 'reg_no', 'firstname', 'surname')
        ->first();

    if (!$student) {
        return response()->json(['message' => 'Student not found for this Reg No.'], 404);
    }

    $studentId = (int) $student->id;

    // ✅ Parent ownership check (fixed)
    $isParent = strtolower((string)($user->role ?? '')) === 'parent';
    if ($isParent) {
        $link = DB::table('parent_students')
            ->where('parent_id', $user->id)
            ->where('student_id', $studentId)
            ->first();

        if (!$link) {
            return response()->json(['message' => 'You are not authorized to view this student.'], 403);
        }

        if (isset($link->school_id) && (int) $link->school_id !== (int) $schoolId) {
            return response()->json(['message' => 'Invalid school context.'], 403);
        }
    }

    $rows = PaymentReceipt::where('school_id', $schoolId)
        ->where('student_id', $studentId)
        ->latest()
        ->get()
        ->map(function ($r) {
            $paths = [];
            try {
                $decoded = json_decode($r->receipt_path ?? '[]', true);
                if (is_array($decoded)) $paths = $decoded;
            } catch (\Throwable $e) {}

            return [
                'id' => $r->id,
                'student_id' => $r->student_id,
                'school_id' => $r->school_id,
                'payment_method' => $r->payment_method,
                'status' => $r->status,
                'files' => array_map(fn($p) => asset($p), $paths),
                'created_at' => $r->created_at,
                'updated_at' => $r->updated_at,
            ];
        });

    return response()->json([
        'reg_no' => $student->reg_no,
        'student' => [
            'id' => $studentId,
            'reg_no' => $student->reg_no,
            'name' => trim(($student->firstname ?? '').' '.($student->surname ?? '')),
        ],
        'receipts' => $rows,
    ]);
}







    // Optional: admin endpoints to list/approve/reject receipts
  public function listReceipts(Request $request)
{
    $schoolId = Auth::user()->school_id;

    $receipts = PaymentReceipt::where('school_id', $schoolId)
        ->with('student')
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($r) {
            return [
                'payment_id' => $r->id,
                'student' => $r->student,
                'payment_method' => $r->payment_method,
                'status' => $r->status,
                'receipts' => json_decode($r->receipt_path, true) ?: [],
            ];
        });

    return response()->json($receipts);
}




public function updateStatus(Request $request, $id)
{
    $request->validate([
        'status' => 'required|in:pending,approved,rejected',
        'notes' => 'nullable|string'
    ]);

    $receipt = PaymentReceipt::with('student')->findOrFail($id);
    $student = $receipt->student;

    // Update receipt
    $receipt->status = $request->status;
    $receipt->notes = $request->notes;
    $receipt->save();

    // Get parent from parent_students table
    $parent = $student->parentAccount();

    if ($parent) {
        // Notification message depending on status
        $statusMessage = match ($request->status) {
            'approved' => "Your child's payment receipt has been approved.",
            'rejected' => "Your child's payment receipt was rejected. Please re-upload.",
            default => "Your child's payment receipt status was updated."
        };

        // Optional: link to where parent can view receipt
        // $actionUrl = url('/parent/receipts/' . $receipt->id);

        // Send notification
        $parent->notify(new SystemNotification(
            $statusMessage,
            $request->status,
            // $actionUrl
        ));
    }

    return response()->json([
        'message' => 'Payment receipt status updated successfully',
        'data' => [
            'payment_id' => $receipt->id,
            'student' => $student,
            'payment_method' => $receipt->payment_method,
            'status' => $receipt->status,
            'receipts' => json_decode($receipt->receipt_path, true) ?: [],
            'notes' => $receipt->notes,
        ]
    ]);
}





}
