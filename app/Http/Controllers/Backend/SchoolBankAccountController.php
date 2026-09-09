<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Mail\SchoolSecurityAlertMail;
use App\Models\SchoolBankAccount;
use App\Models\User;
use App\Services\MonnifyService;
use App\Services\SchoolBillingService;
use App\Services\WemaAlatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SchoolBankAccountController extends Controller
{
    public function __construct(
        private SchoolBillingService $billing,
        private MonnifyService $monnify,
        private WemaAlatService $wemaService
    ) {
    }

    /**
     * Find the master School Owner/Admin for the school
     */
    private function getSchoolOwner(Request $request): User
    {
        $user = $request->user();
        if (!$user->school_id) {
            return $user;
        }

        $owner = User::where('school_id', $user->school_id)
            ->where('role', 'Admin')
            ->orderBy('id')
            ->first();

        return $owner ?: $user;
    }

    /**
     * Validate security verification for bank account modification
     */
    private function validateSecurityGuard(Request $request, User $owner): ?\Illuminate\Http\JsonResponse
    {
        $verifiedKey = "school_security_verified_{$owner->id}_bank_account_update";
        $hasVerifiedSession = Cache::get($verifiedKey) === true;

        if ($hasVerifiedSession) {
            return null; // Already verified in current 15-minute window
        }

        $otp = trim((string) $request->input('security_otp', ''));
        $cacheKey = "school_security_otp_{$owner->id}_bank_account_update";
        $cachedOtp = Cache::get($cacheKey);

        if (!$otp || !$cachedOtp || $cachedOtp !== $otp) {
            return response()->json([
                'status' => 'error',
                'otp_required' => true,
                'message' => 'Security verification required. A 6-digit verification code must be sent to the school proprietor to authorize bank account changes.',
            ], 422);
        }

        // Consume OTP
        Cache::forget($cacheKey);
        Cache::put($verifiedKey, true, now()->addMinutes(15));
        return null;
    }

    // School admin: list own bank accounts
    public function index(Request $request)
    {
        $schoolId = $request->user()->school_id;

        $items = SchoolBankAccount::where('school_id', $schoolId)
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->latest()
            ->get();

        return response()->json($items);
    }

    // School admin: create bank account
    public function store(Request $request)
    {
        $school = $request->user()->school;
        $owner = $this->getSchoolOwner($request);

        // Security check: require Email OTP
        $guardCheck = $this->validateSecurityGuard($request, $owner);
        if ($guardCheck) {
            return $guardCheck;
        }

        $validated = $request->validate([
            'bank_name'       => 'required|string|max:255',
            'bank_code'       => 'required|string|max:20',
            'account_name'    => 'required|string|max:255',
            'account_number'  => 'required|string|max:20',
            'currency'        => 'nullable|string|max:8',
            'is_active'       => 'nullable|boolean',
            'online_payment_enabled' => 'nullable|boolean',
            'accepts_online_payment' => 'nullable|boolean',
            'sort_order'      => 'nullable|integer|min:0|max:1000',
        ]);

        $existing = SchoolBankAccount::where('school_id', $school->id)->first();

        if ($existing) {
            return response()->json([
                'message' => 'A bank account has already been added. Please update the existing account instead.'
            ], 422);
        }

        $onlinePaymentEnabled = $request->has('online_payment_enabled')
            ? $request->boolean('online_payment_enabled')
            : $request->boolean('accepts_online_payment', false);

        DB::beginTransaction();

        try {
            /**
             * STEP 1: Verify the account number via Wema Bank Name Enquiry (with fallback)
             */
            $verify = $this->wemaService->nameEnquiry($validated['bank_code'], $validated['account_number']);

            if (! ($verify['status'] ?? false)) {
                throw new \Exception($verify['message'] ?? 'Invalid bank account details.');
            }

            $resolvedName = $verify['account_name'];
            $validated['account_name'] = $resolvedName;

            $paystackSubaccountCode = null;
            $monnifySubaccountCode = null;

            /**
             * STEP 2: Create Gateway Subaccounts only when online payment is enabled.
             */
            if ($onlinePaymentEnabled) {
                // 1. Monnify Subaccount (Instant settlement)
                try {
                    $monnifySubaccountCode = $this->monnify->createOrUpdateSubaccount(
                        (int) $school->id,
                        $validated['account_number'],
                        $validated['bank_code'],
                        $resolvedName,
                        $school->email ?? null,
                        100.0
                    );
                } catch (\Throwable $monErr) {
                    Log::warning("Could not create Monnify subaccount for school {$school->id}: " . $monErr->getMessage());
                }

                // 2. Paystack Subaccount (Optional fallback)
                if (config('services.paystack.secret')) {
                    try {
                        $subaccount = Http::withToken(config('services.paystack.secret'))
                            ->post('https://api.paystack.co/subaccount', [
                                'business_name'     => $school->school_name ?? $school->name ?? 'School',
                                'settlement_bank'   => $validated['bank_code'],
                                'account_number'    => $validated['account_number'],
                                'percentage_charge' => 0,
                            ]);

                        if ($subaccount->successful() && $subaccount->json('status')) {
                            $paystackSubaccountCode = $subaccount->json('data.subaccount_code');
                        }
                    } catch (\Throwable $payErr) {
                        Log::warning("Could not create Paystack subaccount for school {$school->id}: " . $payErr->getMessage());
                    }
                }
            }

            $item = SchoolBankAccount::create([
                'school_id' => $school->id,
                'bank_name' => $validated['bank_name'],
                'bank_code' => $validated['bank_code'],
                'account_name' => $resolvedName,
                'account_number' => $validated['account_number'],
                'currency' => $validated['currency'] ?? 'NGN',
                'is_active' => $validated['is_active'] ?? true,
                'online_payment_enabled' => $onlinePaymentEnabled,
                'sort_order' => $validated['sort_order'] ?? 0,
                'paystack_subaccount_code' => $paystackSubaccountCode,
                'monnify_subaccount_code' => $monnifySubaccountCode,
                'preferred_gateway' => 'monnify',
            ]);

            try {
                $this->syncSchoolPaymentMode(
                    (int) $school->id,
                    $onlinePaymentEnabled ? 'online' : 'offline',
                    (int) $request->user()->id
                );
            } catch (\Throwable $syncErr) {
                Log::warning("Could not sync payment mode for school {$school->id}: " . $syncErr->getMessage());
            }

            DB::commit();

            // Dispatch instant security alert email to school proprietor
            try {
                Mail::to($owner->email)->send(new SchoolSecurityAlertMail(
                    $owner,
                    'School Bank Account Connected',
                    [
                        'action' => 'New Bank Account Connected for School Fee Collections',
                        'bank_name' => $item->bank_name,
                        'account_name' => $item->account_name,
                        'account_number' => $item->account_number,
                        'online_collections' => $onlinePaymentEnabled ? 'Enabled' : 'Disabled',
                        'authorized_by' => $request->user()->email,
                    ]
                ));
            } catch (\Throwable $mailErr) {
                Log::error("Failed to send Bank Connected alert email: " . $mailErr->getMessage());
            }

            return response()->json([
                'message' => 'Bank account verified and connected successfully.',
                'data' => $item,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function verifyAccount(Request $request)
    {
        $validated = $request->validate([
            'bank_code' => 'required|string',
            'account_number' => 'required|string|size:10',
        ]);

        $verify = $this->wemaService->nameEnquiry($validated['bank_code'], $validated['account_number']);

        if (! ($verify['status'] ?? false)) {
            return response()->json([
                'status' => false,
                'message' => $verify['message'] ?? 'Invalid bank account details.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'account_name' => $verify['account_name'],
            'account_number' => $verify['account_number'],
            'bank_id' => $verify['bank_code'] ?? null,
            'gateway' => $verify['gateway'] ?? 'wema',
        ]);
    }

    // School admin: update bank account
    public function update(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $owner = $this->getSchoolOwner($request);

        // Security check: require Email OTP
        $guardCheck = $this->validateSecurityGuard($request, $owner);
        if ($guardCheck) {
            return $guardCheck;
        }

        $item = SchoolBankAccount::where('school_id', $schoolId)->findOrFail($id);

        $validated = $request->validate([
            'bank_name' => 'sometimes|required|string|max:255',
            'bank_code' => 'nullable|string|max:20',
            'account_name' => 'sometimes|required|string|max:255',
            'account_number' => 'sometimes|required|string|max:20',
            'currency' => 'nullable|string|max:8',
            'is_active' => 'nullable|boolean',
            'online_payment_enabled' => 'nullable|boolean',
            'accepts_online_payment' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:1000',
        ]);

        $accountNumberChanged = array_key_exists('account_number', $validated) && $validated['account_number'] !== $item->account_number;
        $bankCodeChanged = array_key_exists('bank_code', $validated) && !empty($validated['bank_code']) && $validated['bank_code'] !== $item->bank_code;

        $onlinePaymentEnabled = $request->has('online_payment_enabled')
            ? $request->boolean('online_payment_enabled')
            : ($request->has('accepts_online_payment') ? $request->boolean('accepts_online_payment') : (bool) $item->online_payment_enabled);

        DB::beginTransaction();

        try {
            if ($accountNumberChanged || $bankCodeChanged) {
                $bankCode = $validated['bank_code'] ?? $item->bank_code;
                $accountNumber = $validated['account_number'] ?? $item->account_number;

                if ($bankCode && $accountNumber) {
                    $verify = $this->wemaService->nameEnquiry($bankCode, $accountNumber);

                    if (! ($verify['status'] ?? false)) {
                        throw new \Exception($verify['message'] ?? 'Invalid bank account details.');
                    }

                    $validated['account_name'] = $verify['account_name'];
                }
            }

            $validated['online_payment_enabled'] = $onlinePaymentEnabled;
            unset($validated['accepts_online_payment']);

            if ($onlinePaymentEnabled && (! $item->monnify_subaccount_code || $accountNumberChanged || $bankCodeChanged)) {
                try {
                    $monCode = $this->monnify->createOrUpdateSubaccount(
                        (int) $schoolId,
                        $validated['account_number'] ?? $item->account_number,
                        $validated['bank_code'] ?? $item->bank_code,
                        $validated['account_name'] ?? $item->account_name,
                        $request->user()->school?->email ?? null,
                        100.0
                    );
                    $validated['monnify_subaccount_code'] = $monCode;
                } catch (\Throwable $monErr) {
                    Log::warning("Could not sync Monnify subaccount on update for school {$schoolId}: " . $monErr->getMessage());
                }
            }

            $item->update($validated);

            try {
                $this->syncSchoolPaymentMode(
                    (int) $schoolId,
                    $onlinePaymentEnabled ? 'online' : 'offline',
                    (int) $request->user()->id
                );
            } catch (\Throwable $syncErr) {
                Log::warning("Could not sync payment mode for school {$schoolId}: " . $syncErr->getMessage());
            }

            DB::commit();

            // Dispatch instant security alert email to school proprietor
            try {
                Mail::to($owner->email)->send(new SchoolSecurityAlertMail(
                    $owner,
                    'School Bank Account Details Modified',
                    [
                        'action' => 'School Bank Account Updated',
                        'bank_name' => $item->bank_name,
                        'account_name' => $item->account_name,
                        'account_number' => $item->account_number,
                        'online_collections' => $onlinePaymentEnabled ? 'Enabled' : 'Disabled',
                        'authorized_by' => $request->user()->email,
                    ]
                ));
            } catch (\Throwable $mailErr) {
                Log::error("Failed to send Bank Updated alert email: " . $mailErr->getMessage());
            }

            return response()->json([
                'message' => 'Bank account updated successfully.',
                'data' => $item,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // School admin: delete bank account
    public function destroy(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $owner = $this->getSchoolOwner($request);

        // Security check: require Email OTP
        $guardCheck = $this->validateSecurityGuard($request, $owner);
        if ($guardCheck) {
            return $guardCheck;
        }

        $item = SchoolBankAccount::where('school_id', $schoolId)->findOrFail($id);
        $bankName = $item->bank_name;
        $accountNumber = $item->account_number;
        $item->delete();

        try {
            Mail::to($owner->email)->send(new SchoolSecurityAlertMail(
                $owner,
                'School Bank Account Disconnected',
                [
                    'action' => 'School Bank Account Removed',
                    'disconnected_bank' => $bankName,
                    'account_number' => $accountNumber,
                    'authorized_by' => $request->user()->email,
                ]
            ));
        } catch (\Throwable $mailErr) {
            Log::error("Failed to send Bank Disconnected alert email: " . $mailErr->getMessage());
        }

        return response()->json(['message' => 'Bank account deleted.']);
    }

    // Parent / public within auth: get active accounts for a school
    public function activeForSchool(Request $request, $schoolId)
    {
        $items = SchoolBankAccount::where('school_id', $schoolId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id','bank_name','bank_code','account_name','account_number','currency','online_payment_enabled']);

        return response()->json($items);
    }

    public function banks()
    {
        return Cache::remember('nigerian_banks_list', 86400, function () {
            try {
                $response = Http::withToken(config('services.paystack.secret'))
                    ->timeout(10)
                    ->get('https://api.paystack.co/bank', [
                        'country' => 'nigeria',
                        'perPage' => 100,
                    ]);

                if ($response->successful() && is_array($response->json('data'))) {
                    return response()->json($response->json('data'));
                }
            } catch (\Throwable $e) {
                Log::warning('Could not fetch banks from Paystack: ' . $e->getMessage());
            }

            // Standard fallback of major Nigerian commercial banks and FinTechs
            $fallbackBanks = [
                ['name' => 'Wema Bank (ALAT)', 'code' => '035'],
                ['name' => 'Guaranty Trust Bank (GTBank)', 'code' => '058'],
                ['name' => 'Zenith Bank', 'code' => '057'],
                ['name' => 'Access Bank', 'code' => '044'],
                ['name' => 'First Bank of Nigeria', 'code' => '011'],
                ['name' => 'United Bank For Africa (UBA)', 'code' => '033'],
                ['name' => 'Moniepoint Microfinance Bank', 'code' => '090405'],
                ['name' => 'OPay (PayCom)', 'code' => '100004'],
                ['name' => 'PalmPay', 'code' => '100033'],
                ['name' => 'Kuda Microfinance Bank', 'code' => '090267'],
                ['name' => 'Fidelity Bank', 'code' => '070'],
                ['name' => 'Stanbic IBTC Bank', 'code' => '221'],
                ['name' => 'Sterling Bank', 'code' => '232'],
                ['name' => 'Union Bank of Nigeria', 'code' => '032'],
                ['name' => 'Providus Bank', 'code' => '101'],
                ['name' => 'Ecobank Nigeria', 'code' => '050'],
                ['name' => 'FCMB (First City Monument Bank)', 'code' => '214'],
                ['name' => 'Polaris Bank', 'code' => '076'],
                ['name' => 'Keystone Bank', 'code' => '082'],
                ['name' => 'Jaiz Bank', 'code' => '301'],
                ['name' => 'TAJ Bank', 'code' => '302'],
                ['name' => 'VFD Microfinance Bank', 'code' => '090110'],
                ['name' => 'FairMoney Microfinance Bank', 'code' => '090551'],
                ['name' => 'Rubies MFB', 'code' => '090175'],
            ];

            return response()->json($fallbackBanks);
        });
    }

    private function syncSchoolPaymentMode(int $schoolId, string $paymentMode, int $actorId): void
    {
        $this->billing->updateSettings($schoolId, [
            'payment_mode' => $paymentMode,
            'block_results_when_unpaid' => true,
        ], $actorId);
    }
}
