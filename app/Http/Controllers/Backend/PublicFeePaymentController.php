<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PublicFeePaymentIntent;
use App\Models\SchoolBankAccount;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\PlatformFeeService;
use App\Services\SalesCommissionService;
use App\Services\SchoolBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PublicFeePaymentController extends Controller
{
    public function __construct(
        private PlatformFeeService $platformFeeService,
        private SchoolBillingService $schoolBillingService,
        private SalesCommissionService $salesCommissionService
    ) {
    }

    public function school(Request $request)
    {
        $data = $request->validate([
            'school_code' => 'required|string|max:80',
        ]);

        $admin = $this->resolveSchoolAdmin($data['school_code']);

        if (! $admin) {
            return response()->json(['message' => 'School code not found.'], 404);
        }

        return response()->json([
            'school' => $this->schoolPayload($admin),
        ]);
    }

    public function student(Request $request)
    {
        $data = $request->validate([
            'school_code' => 'required|string|max:80',
            'student_reg_no' => 'required|string|max:80',
        ]);

        $admin = $this->resolveSchoolAdmin($data['school_code']);

        if (! $admin) {
            return response()->json(['message' => 'School code not found.'], 404);
        }

        $student = $this->resolveStudent($admin->school_id, $data['student_reg_no']);

        if (! $student) {
            return response()->json(['message' => 'Student admission number was not found for this school.'], 404);
        }

        return response()->json($this->studentFeePayload($admin, $student));
    }

    public function initialize(Request $request)
    {
        $data = $request->validate([
            'school_code' => 'required|string|max:80',
            'student_reg_no' => 'required|string|max:80',
            'amount' => 'required|numeric|min:100',
            'payer_email' => 'nullable|email|max:190',
            'payer_name' => 'nullable|string|max:190',
            'payer_phone' => 'nullable|string|max:40',
        ]);

        $admin = $this->resolveSchoolAdmin($data['school_code']);

        if (! $admin) {
            return response()->json(['message' => 'School code not found.'], 404);
        }

        $student = $this->resolveStudent($admin->school_id, $data['student_reg_no']);

        if (! $student) {
            return response()->json(['message' => 'Student admission number was not found for this school.'], 404);
        }

        $amount = round((float) $data['amount'], 2);
        $outstanding = $this->outstandingFees($admin->school_id, $student->id);
        $totalBalance = round((float) $outstanding->sum('balance'), 2);

        if ($totalBalance <= 0) {
            return response()->json(['message' => 'This student does not have an outstanding fee balance.'], 422);
        }

        if ($amount > $totalBalance) {
            return response()->json([
                'message' => 'Amount exceeds the student outstanding balance.',
                'balance' => $totalBalance,
            ], 422);
        }

        $bankAccount = SchoolBankAccount::where('school_id', $admin->school_id)
            ->where('is_active', true)
            ->where('online_payment_enabled', true)
            ->whereNotNull('paystack_subaccount_code')
            ->orderBy('sort_order')
            ->first();

        if (! $bankAccount) {
            return response()->json(['message' => 'This school has not enabled online fee payment yet.'], 422);
        }

        $reference = 'gq_fee_' . Str::uuid()->toString();
        $allocations = $this->buildAllocations($outstanding, $amount);

        if (empty($allocations)) {
            return response()->json(['message' => 'No payable fee record was found for this student.'], 422);
        }

        try {
            $platformFee = $this->attachPlatformFeesToAllocations($outstanding, $allocations, $reference);
        } catch (RuntimeException $e) {
            $this->platformFeeService->releaseCharge($reference);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payerEmail = $data['payer_email']
            ?? $student->email
            ?? $admin->email
            ?? ('payments+' . Str::lower(Str::random(10)) . '@gradequest.local');

        $payload = [
            'reference' => $reference,
            'email' => $payerEmail,
            'amount' => (int) round($amount * 100),
            'subaccount' => $bankAccount->paystack_subaccount_code,
            'bearer' => 'account',
            'callback_url' => rtrim((string) config('app.frontend_url'), '/') . '/pay-school-fee?reference=' . $reference,
            'metadata' => [
                'source' => 'public_fee_payment',
                'school_id' => $admin->school_id,
                'student_id' => $student->id,
                'student_reg_no' => $student->reg_no,
            ],
        ];

        if ($platformFee > 0) {
            $payload['transaction_charge'] = $platformFee * 100;
        }

        $response = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/transaction/initialize', $payload);

        if (! $response->successful() || ! $response->json('status')) {
            $this->platformFeeService->releaseCharge($reference);

            return response()->json([
                'message' => $response->json('message') ?: 'Could not initialize Paystack payment.',
            ], 422);
        }

        PublicFeePaymentIntent::create([
            'school_id' => $admin->school_id,
            'student_id' => $student->id,
            'school_code' => trim($data['school_code']),
            'student_reg_no' => trim($data['student_reg_no']),
            'reference' => $reference,
            'payer_email' => $payerEmail,
            'payer_name' => $data['payer_name'] ?? null,
            'payer_phone' => $data['payer_phone'] ?? null,
            'amount' => $amount,
            'platform_fee' => $platformFee,
            'allocations' => $allocations,
            'status' => 'pending',
        ]);

        return response()->json([
            'authorization_url' => $response->json('data.authorization_url'),
            'access_code' => $response->json('data.access_code'),
            'reference' => $reference,
        ]);
    }

    public function verify(string $reference)
    {
        $intent = PublicFeePaymentIntent::where('reference', $reference)->firstOrFail();

        if ($intent->status !== 'pending') {
            return response()->json([
                'status' => $intent->status,
                'reference' => $intent->reference,
                'amount' => (float) $intent->amount,
                'student' => $intent->student ? $this->studentPayload($intent->student) : null,
            ]);
        }

        $response = Http::withToken(config('services.paystack.secret'))
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (! $response->successful()) {
            return response()->json(['message' => 'Could not verify Paystack payment.'], 422);
        }

        $status = $response->json('data.status');

        if ($status !== 'success') {
            $intent->update([
                'status' => 'failed',
                'paystack_response' => $response->json('data'),
            ]);
            $this->platformFeeService->releaseCharge($reference);

            return response()->json([
                'status' => 'failed',
                'reference' => $reference,
                'message' => 'Payment was not successful.',
            ], 422);
        }

        DB::transaction(function () use ($intent, $response, $reference) {
            $locked = PublicFeePaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                return;
            }

            $remaining = (float) $locked->amount;
            $allocations = $locked->allocations ?: [];

            foreach ($allocations as $index => $allocation) {
                if ($remaining <= 0) {
                    break;
                }

                $studentFee = StudentFee::where('school_id', $locked->school_id)
                    ->where('student_id', $locked->student_id)
                    ->where('id', $allocation['student_fee_id'] ?? null)
                    ->lockForUpdate()
                    ->first();

                if (! $studentFee || (float) $studentFee->balance <= 0) {
                    continue;
                }

                $amount = min((float) ($allocation['amount'] ?? 0), (float) $studentFee->balance, $remaining);

                if ($amount <= 0) {
                    continue;
                }

                $paymentReference = $index === 0 ? $reference : $reference . '-' . ($index + 1);

                $payment = Payment::create([
                    'student_fee_id' => $studentFee->id,
                    'school_id' => $locked->school_id,
                    'amount' => $amount,
                    'platform_fee' => (float) ($allocation['platform_fee'] ?? 0),
                    'payment_method' => 'paystack',
                    'reference' => $paymentReference,
                    'status' => 'success',
                    'paid_by' => null,
                    'received_by' => null,
                    'email' => $locked->payer_email,
                    'paystack_response' => $response->json('data'),
                ]);

                $amountPaid = (float) $studentFee->amount_paid + $amount;
                $balance = max(0, (float) $studentFee->total_amount - $amountPaid);

                $studentFee->update([
                    'amount_paid' => $amountPaid,
                    'balance' => $balance,
                    'status' => $balance <= 0 ? 'paid' : 'partial',
                ]);

                if ((float) ($allocation['platform_fee'] ?? 0) > 0) {
                    $this->schoolBillingService->markOnlineEntitlementFromPayment($payment->fresh());
                }

                $this->salesCommissionService->recordFeePaymentCommission($payment->fresh());

                $remaining = round($remaining - $amount, 2);
            }

            if ((float) $locked->platform_fee > 0) {
                $this->platformFeeService->confirmCharge($reference);
            }

            $locked->update([
                'status' => 'success',
                'paystack_response' => $response->json('data'),
                'paid_at' => now(),
            ]);
        });

        $intent = $intent->fresh(['student']);

        return response()->json([
            'status' => 'success',
            'reference' => $intent->reference,
            'amount' => (float) $intent->amount,
            'student' => $intent->student ? $this->studentPayload($intent->student) : null,
        ]);
    }

    private function resolveSchoolAdmin(string $schoolCode): ?User
    {
        return User::with('school')
            ->whereRaw('LOWER(role) = ?', ['admin'])
            ->where('reg_no', trim($schoolCode))
            ->whereNotNull('school_id')
            ->first();
    }

    private function resolveStudent(int $schoolId, string $studentRegNo): ?User
    {
        return User::with(['level:id,name', 'section:id,name'])
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('reg_no', trim($studentRegNo))
            ->first();
    }

    private function outstandingFees(int $schoolId, int $studentId)
    {
        return StudentFee::with(['feeType:id,name', 'session:id,name', 'term:id,name'])
            ->where('school_id', $schoolId)
            ->where('student_id', $studentId)
            ->where('balance', '>', 0)
            ->orderBy('session_id')
            ->orderBy('term_id')
            ->orderBy('id')
            ->get();
    }

    private function buildAllocations($fees, float $amount): array
    {
        $remaining = $amount;
        $allocations = [];

        foreach ($fees as $fee) {
            if ($remaining <= 0) {
                break;
            }

            $payable = min((float) $fee->balance, $remaining);

            if ($payable <= 0) {
                continue;
            }

            $allocations[] = [
                'student_fee_id' => (int) $fee->id,
                'amount' => round($payable, 2),
            ];

            $remaining = round($remaining - $payable, 2);
        }

        return $allocations;
    }

    private function attachPlatformFeesToAllocations($fees, array &$allocations, string $reference): int
    {
        $platformFee = 0;

        foreach ($allocations as &$allocation) {
            $fee = $fees->firstWhere('id', $allocation['student_fee_id'] ?? null);

            if (! $fee) {
                $allocation['platform_fee'] = 0;
                continue;
            }

            $charge = $this->platformFeeService->resolveCharge(
                $fee,
                (int) round((float) ($allocation['amount'] ?? 0)),
                $reference
            );

            $allocation['platform_fee'] = $charge;
            $platformFee += $charge;
        }

        unset($allocation);

        return $platformFee;
    }

    private function studentFeePayload(User $admin, User $student): array
    {
        $fees = $this->outstandingFees($admin->school_id, $student->id);

        return [
            'school' => $this->schoolPayload($admin),
            'student' => $this->studentPayload($student),
            'summary' => [
                'total_amount' => round((float) $fees->sum('total_amount'), 2),
                'amount_paid' => round((float) $fees->sum('amount_paid'), 2),
                'balance' => round((float) $fees->sum('balance'), 2),
                'outstanding_items' => $fees->count(),
            ],
            'fees' => $fees->map(fn (StudentFee $fee) => [
                'id' => $fee->id,
                'name' => $fee->feeType?->name ?? 'Fee',
                'session' => $fee->session?->name,
                'term' => $fee->term?->name,
                'total_amount' => (float) $fee->total_amount,
                'amount_paid' => (float) $fee->amount_paid,
                'balance' => (float) $fee->balance,
                'status' => $fee->status,
            ])->values(),
        ];
    }

    private function schoolPayload(User $admin): array
    {
        return [
            'id' => $admin->school_id,
            'name' => $admin->school?->school_name ?? $admin->school?->name ?? 'School',
            'code' => $admin->reg_no,
            'email' => $admin->school?->email ?? $admin->email,
            'phone' => $admin->school?->phone ?? $admin->phone,
        ];
    }

    private function studentPayload(User $student): array
    {
        return [
            'id' => $student->id,
            'name' => trim(($student->surname ?? '') . ' ' . ($student->firstname ?? '')),
            'reg_no' => $student->reg_no,
            'class' => $student->level?->name,
            'section' => $student->section?->name,
        ];
    }
}
