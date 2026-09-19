<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\SchoolAdmissionApplication;
use App\Models\SchoolAdmissionPayment;
use App\Models\SchoolAdmissionSetting;
use App\Models\SchoolDomain;
use App\Models\SchoolSetting;
use App\Models\StudentClass;
use App\Services\WemaAlatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PublicAdmissionController extends Controller
{
    public function __construct(
        private WemaAlatService $wemaService
    ) {
    }

    /**
     * Resolve school admission form configuration.
     */
    public function info(Request $request, string $identifier = null): JsonResponse
    {
        $school = $this->resolveSchool($request, $identifier);

        if (! $school) {
            return response()->json(['message' => 'School not found.'], 404);
        }

        $admissionSetting = SchoolAdmissionSetting::firstOrCreate(
            ['school_id' => $school->id],
            SchoolAdmissionSetting::defaultSettings($school)
        );

        $classes = StudentClass::where('school_id', $school->id)
            ->whereNull('archived_at')
            ->select('id', 'name')
            ->get();

        $activeSession = AcademicSession::where('school_id', $school->id)
            ->where('is_current', 1)
            ->first();

        return response()->json([
            'status' => true,
            'school' => [
                'id' => $school->id,
                'name' => $school->school_name,
                'email' => $school->email,
                'phone' => $school->phone ?: $school->phone_number,
                'address' => $school->address,
                'logo' => $this->formatAssetUrl($school->logo),
                'logo_url' => $this->formatAssetUrl($school->logo),
                'principal_signature' => $this->formatAssetUrl($school->principal_signature),
                'custom_domain' => $school->custom_domain,
            ],
            'admission' => [
                'is_open' => (bool) $admissionSetting->is_open,
                'session_name' => $admissionSetting->admission_session_name ?: ($activeSession?->name ?? '2026/2027 Session'),
                'application_fee' => (float) $admissionSetting->application_fee,
                'platform_fee' => (float) $admissionSetting->platform_fee,
                'total_fee' => (float) ($admissionSetting->application_fee + $admissionSetting->platform_fee),
                'require_payment' => (bool) $admissionSetting->require_payment,
                'instructions' => $admissionSetting->instructions,
                'requirements' => $admissionSetting->requirements,
                'contact_email' => $admissionSetting->contact_email,
                'contact_phone' => $admissionSetting->contact_phone,
            ],
            'classes' => $classes,
        ]);
    }

    /**
     * Submit candidate admission application & generate Wema Bank payment.
     */
    public function submit(Request $request, string $identifier = null): JsonResponse
    {
        $school = $this->resolveSchool($request, $identifier);

        if (! $school) {
            return response()->json(['message' => 'School not found.'], 404);
        }

        $admissionSetting = SchoolAdmissionSetting::firstOrCreate(
            ['school_id' => $school->id],
            SchoolAdmissionSetting::defaultSettings($school)
        );

        if (! $admissionSetting->is_open) {
            return response()->json(['message' => 'Admissions are currently closed for this school.'], 422);
        }

        $validated = $request->validate([
            // Candidate
            'firstname' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'other_names' => 'nullable|string|max:100',
            'dob' => 'nullable|date',
            'gender' => 'required|in:Male,Female',
            'blood_group' => 'nullable|string|max:10',
            'religion' => 'nullable|string|max:100',
            'nationality' => 'nullable|string|max:100',
            'state_of_origin' => 'nullable|string|max:100',
            'lga_of_origin' => 'nullable|string|max:100',
            'home_address' => 'nullable|string|max:500',
            'passport_photo' => 'nullable|string', // base64 or url

            // Academic
            'level_id' => 'nullable|integer',
            'applied_class_name' => 'required|string|max:100',
            'department_id' => 'nullable|integer',
            'previous_school' => 'nullable|string|max:255',
            'previous_class' => 'nullable|string|max:100',
            'last_grade_average' => 'nullable|string|max:50',

            // Parent / Guardian
            'parent_name' => 'required|string|max:200',
            'parent_phone' => 'required|string|max:30',
            'parent_email' => 'nullable|email|max:150',
            'parent_address' => 'nullable|string|max:500',
            'parent_occupation' => 'nullable|string|max:150',
            'parent_relationship' => 'required|string|max:50',
        ]);

        // Upload photo if multipart file provided
        $passportPhotoUrl = $validated['passport_photo'] ?? null;
        if ($request->hasFile('passport_file')) {
            $file = $request->file('passport_file');
            $fileName = 'applicant_' . Str::random(16) . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs("uploads/schools/{$school->id}/admissions", $fileName, 'public');
            $passportPhotoUrl = asset('storage/' . $path);
        }

        // Active Session
        $activeSession = AcademicSession::where('school_id', $school->id)->where('is_current', 1)->first();

        // Generate Application Number: ADM-2026-XXXXX
        $year = date('Y');
        $randomSeq = str_pad((string) random_int(100, 99999), 5, '0', STR_PAD_LEFT);
        $appNumber = "ADM-{$year}-{$randomSeq}";
        while (SchoolAdmissionApplication::where('application_number', $appNumber)->exists()) {
            $randomSeq = str_pad((string) random_int(100, 99999), 5, '0', STR_PAD_LEFT);
            $appNumber = "ADM-{$year}-{$randomSeq}";
        }

        return DB::transaction(function () use ($school, $admissionSetting, $validated, $passportPhotoUrl, $activeSession, $appNumber) {
            $schoolFee = (float) $admissionSetting->application_fee;
            $platformFee = (float) $admissionSetting->platform_fee;
            $totalFee = $schoolFee + $platformFee;

            $requiresPayment = $admissionSetting->require_payment && $totalFee > 0;

            $application = SchoolAdmissionApplication::create([
                'school_id' => $school->id,
                'application_number' => $appNumber,
                'session_id' => $activeSession?->id,
                'level_id' => $validated['level_id'] ?? null,
                'department_id' => $validated['department_id'] ?? null,
                'applied_class_name' => $validated['applied_class_name'],
                'firstname' => $validated['firstname'],
                'surname' => $validated['surname'],
                'other_names' => $validated['other_names'] ?? null,
                'dob' => $validated['dob'] ?? null,
                'gender' => $validated['gender'],
                'blood_group' => $validated['blood_group'] ?? null,
                'religion' => $validated['religion'] ?? null,
                'nationality' => $validated['nationality'] ?? 'Nigerian',
                'state_of_origin' => $validated['state_of_origin'] ?? null,
                'lga_of_origin' => $validated['lga_of_origin'] ?? null,
                'home_address' => $validated['home_address'] ?? null,
                'passport_photo' => $passportPhotoUrl,
                'previous_school' => $validated['previous_school'] ?? null,
                'previous_class' => $validated['previous_class'] ?? null,
                'last_grade_average' => $validated['last_grade_average'] ?? null,
                'parent_name' => $validated['parent_name'],
                'parent_phone' => $validated['parent_phone'],
                'parent_email' => $validated['parent_email'] ?? null,
                'parent_address' => $validated['parent_address'] ?? null,
                'parent_occupation' => $validated['parent_occupation'] ?? null,
                'parent_relationship' => $validated['parent_relationship'],
                'status' => 'submitted',
                'payment_status' => $requiresPayment ? 'pending' : 'waived',
            ]);

            $paymentData = null;

            if ($requiresPayment) {
                // Generate Wema Bank Dedicated Virtual Account for Instant Split Payment
                $wemaRef = 'WEMA_ADM_' . strtoupper(Str::random(12));
                $candidateFullName = trim("{$validated['firstname']} {$validated['surname']}");

                $virtualAccount = $this->wemaService->generateVirtualAccount([
                    'reference' => $wemaRef,
                    'amount' => $totalFee,
                    'student_name' => $candidateFullName,
                    'school_name' => $school->school_name,
                    'email' => $validated['parent_email'] ?? 'admissions@schoolprofit.ng',
                    'phone' => $validated['parent_phone'] ?? '08000000000',
                    'school_code' => 'SCH' . $school->id,
                ]);

                $payment = SchoolAdmissionPayment::create([
                    'school_id' => $school->id,
                    'application_id' => $application->id,
                    'reference' => $wemaRef,
                    'total_amount' => $totalFee,
                    'school_amount' => $schoolFee,
                    'platform_fee' => $platformFee,
                    'gateway' => 'wema_alat',
                    'channel' => 'wema_virtual_account',
                    'wema_virtual_account_number' => $virtualAccount['account_number'] ?? '',
                    'wema_account_name' => $virtualAccount['account_name'] ?? "SchoolProfit / {$candidateFullName}",
                    'wema_reference' => $wemaRef,
                    'status' => 'pending',
                    'gateway_response' => [
                        'bank_name' => $virtualAccount['bank_name'] ?? 'Wema Bank',
                        'account_number' => $virtualAccount['account_number'] ?? '',
                        'account_name' => $virtualAccount['account_name'] ?? "SchoolProfit / {$candidateFullName}",
                        'expires_at' => $virtualAccount['expires_at'] ?? now()->addHours(24)->toIso8601String(),
                        'mode' => $virtualAccount['mode'] ?? 'sandbox',
                    ],
                ]);

                $paymentData = [
                    'reference' => $wemaRef,
                    'total_amount' => $totalFee,
                    'school_fee' => $schoolFee,
                    'platform_fee' => $platformFee,
                    'bank_name' => 'Wema Bank',
                    'account_number' => $virtualAccount['account_number'],
                    'account_name' => $virtualAccount['account_name'],
                    'expires_at' => $virtualAccount['expires_at'],
                ];
            }

            return response()->json([
                'status' => true,
                'message' => 'Admission application submitted successfully.',
                'application' => $application,
                'payment' => $paymentData,
                'requires_payment' => $requiresPayment,
            ], 201);
        });
    }

    /**
     * Verify Wema Bank payment for an admission application.
     */
    public function verifyPayment(Request $request, string $reference): JsonResponse
    {
        $payment = SchoolAdmissionPayment::where('reference', $reference)
            ->with(['application', 'school'])
            ->firstOrFail();

        if ($payment->status === 'successful') {
            return response()->json([
                'status' => true,
                'message' => 'Payment already verified.',
                'payment' => $payment,
                'application' => $payment->application,
            ]);
        }

        // Verify with Wema Bank
        $wemaResult = $this->wemaService->verifyTransaction($reference);

        // Accept if status is successful or simulate verification in sandbox
        if (($wemaResult['status'] ?? false) || config('services.wema_alat.env', 'sandbox') === 'sandbox') {
            $payment->update([
                'status' => 'successful',
                'paid_at' => now(),
                'settled_at' => now(),
            ]);

            $payment->application->update([
                'payment_status' => 'paid',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Payment confirmed successfully! Your application is now active.',
                'payment' => $payment->fresh(),
                'application' => $payment->application->fresh(),
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Payment has not been received yet. Please ensure you transferred the exact amount to the Wema Bank account provided.',
        ], 422);
    }

    /**
     * Track admission application status by application number or parent phone.
     */
    public function track(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:3|max:100',
        ]);

        $query = trim($request->input('query'));

        $applications = SchoolAdmissionApplication::where('application_number', $query)
            ->orWhere('parent_phone', $query)
            ->with(['school:id,school_name,logo,email,phone,address', 'payment'])
            ->latest()
            ->get();

        if ($applications->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No admission application found matching this reference or phone number.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'applications' => $applications,
        ]);
    }

    /**
     * Get official admission application slip data.
     */
    public function slip(Request $request, string $applicationNumber): JsonResponse
    {
        $application = SchoolAdmissionApplication::where('application_number', $applicationNumber)
            ->with(['school', 'payment', 'level', 'department'])
            ->firstOrFail();

        return response()->json([
            'status' => true,
            'application' => $application,
            'school' => $application->school,
            'qr_payload' => [
                'app_no' => $application->application_number,
                'candidate' => "{$application->firstname} {$application->surname}",
                'class' => $application->applied_class_name,
                'school' => $application->school?->school_name,
                'status' => $application->status,
                'payment_status' => $application->payment_status,
                'verify_url' => config('app.frontend_url') . "/admissions/status?query=" . $application->application_number,
            ],
        ]);
    }

    /**
     * Helper to resolve school from domain or identifier.
     */
    private function resolveSchool(Request $request, ?string $identifier): ?SchoolSetting
    {
        $school = null;

        if (! empty($identifier) && $identifier !== 'current') {
            if (is_numeric($identifier)) {
                $school = SchoolSetting::find($identifier);
            } else {
                $school = SchoolSetting::where('school_subdomain', $identifier)
                    ->orWhere('custom_domain', $identifier)
                    ->first();
            }
        }

        if (! $school) {
            $host = strtolower(trim($request->getHost(), '. '));
            $domainRecord = SchoolDomain::where('domain', $host)->where('status', 'active')->first();
            if ($domainRecord) {
                $school = SchoolSetting::find($domainRecord->school_id);
            } elseif ($hostSetting = SchoolSetting::where('custom_domain', $host)->first()) {
                $school = $hostSetting;
            }
        }

        if (! $school) {
            $school = SchoolSetting::first();
        }

        return $school;
    }

    private function formatAssetUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return $path;
        }

        $clean = ltrim($path, '/');
        if (str_starts_with($clean, 'uploads/')) {
            return url($clean);
        }
        if (str_starts_with($clean, 'storage/')) {
            return url($clean);
        }

        return url('storage/' . $clean);
    }
}
