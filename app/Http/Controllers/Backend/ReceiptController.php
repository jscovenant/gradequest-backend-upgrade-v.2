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

class ReceiptController extends Controller
{
 
public function uploadReceipts(Request $request)
{
    // Accept normal form-data + JSON fields
    $request->validate([
        'student_id' => 'required|exists:users,id',
        'payment_method' => 'required|in:online,cash',
        'term_id' => 'nullable|exists:terms,id',
        'session_id' => 'nullable|exists:academic_sessions,id',
        'amount' => 'nullable|numeric',
        'receipts.*' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
    ]);

    $schoolId = Auth::user()->school_id;

    $destinationPath = public_path('uploads/receipts');
    if (!file_exists($destinationPath)) {
        mkdir($destinationPath, 0775, true);
    }

    $uploadedPaths = [];

    // Save files
    if ($request->hasFile('receipts')) {
        foreach ($request->file('receipts') as $file) {

            $fileName = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
            $file->move($destinationPath, $fileName);

            $uploadedPaths[] = 'uploads/receipts/'.$fileName;
        }
    }

    // Save record in DB
    $receipt = PaymentReceipt::create([
        'student_id' => $request->student_id,
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
            'status' => $receipt->status
        ],
    ]);
}



public function getAccountDetails($schoolId)
{
    $gateway = PaymentGateway::where('school_id', $schoolId)->first();

    if (!$gateway) {
        return response()->json([
            'message' => 'Account details not found',
            'account' => null
        ], 404);
    }

    return response()->json([
        'account' => [
            'bank_name' => $gateway->bank_name,
            'account_number' => $gateway->account_number,
            'account_name' => $gateway->account_name,
        ]
    ], 200);
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
