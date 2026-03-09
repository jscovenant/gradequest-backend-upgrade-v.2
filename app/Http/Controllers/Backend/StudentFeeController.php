<?php

namespace App\Http\Controllers\Backend;


use App\Http\Controllers\Controller;
use App\Models\StudentFee;
use App\Models\PaymentReceipt;
use App\Models\Payment;
use App\Models\FeeType;
use App\Models\Term;
use App\Models\AcademicSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudentFeeController extends Controller
{
    



public function MyFee(Request $request)
{
    $student = Auth::user();

    if (strtolower($student->role) !== 'student') {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    // ✅ Eager-load feeType relationship
    $fees = StudentFee::with('feeType')
        ->where('student_id', $student->id)
        ->orderBy('created_at', 'desc')
        ->paginate(10);

    $totalFees = StudentFee::where('student_id', $student->id)->sum('total_amount');
    $totalPaid = StudentFee::where('student_id', $student->id)->sum('amount_paid');
    $balance = StudentFee::where('student_id', $student->id)->sum('balance');

    $lastPaymentDate = StudentFee::where('student_id', $student->id)
        ->latest('updated_at')
        ->value('updated_at');

    return response()->json([
        'student' => [
            'name' => "{$student->surname} {$student->firstname}",
            'reg_no' => $student->reg_no,
        ],
        'summary' => [
            'total_fees' => $totalFees,
            'total_paid' => $totalPaid,
            'balance' => $balance,
            'last_payment_date' => $lastPaymentDate,
        ],
        'fees' => $fees,
    ]);
}













}
