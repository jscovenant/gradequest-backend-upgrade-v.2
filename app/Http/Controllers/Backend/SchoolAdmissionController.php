<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\ParentStudent;
use App\Models\SchoolAdmissionApplication;
use App\Models\SchoolAdmissionPayment;
use App\Models\SchoolAdmissionSetting;
use App\Models\SchoolSetting;
use App\Models\Section;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SchoolAdmissionController extends Controller
{
    /**
     * Resolve school safely.
     */
    protected function resolveSchool(Request $request): ?SchoolSetting
    {
        $user = Auth::user();
        $schoolId = $user->school_id ?? $request->query('school_id') ?? $request->input('school_id');
        if ($schoolId) {
            $school = SchoolSetting::find($schoolId);
            if ($school) {
                return $school;
            }
        }
        if ($user && ($user->role === 'Super-Admin' || (method_exists($user, 'isSuperAdminUser') && $user->isSuperAdminUser()))) {
            return SchoolSetting::first();
        }
        return null;
    }

    /**
     * Get School Admission Settings.
     */
    public function getSettings(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $settings = SchoolAdmissionSetting::firstOrCreate(
            ['school_id' => $school->id],
            SchoolAdmissionSetting::defaultSettings($school)
        );

        $classes = StudentClass::where('school_id', $school->id)
            ->whereNull('archived_at')
            ->select('id', 'name')
            ->get();

        $sessions = AcademicSession::where('school_id', $school->id)->get();

        return response()->json([
            'status' => true,
            'settings' => $settings,
            'classes' => $classes,
            'sessions' => $sessions,
            'school' => $school,
            'direct_form_url' => $school->custom_domain ? "https://{$school->custom_domain}/admission" : config('app.frontend_url') . "/school/{$school->id}/admission",
        ]);
    }

    /**
     * Update School Admission Settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $settings = SchoolAdmissionSetting::firstOrCreate(
            ['school_id' => $school->id],
            SchoolAdmissionSetting::defaultSettings($school)
        );

        $validated = $request->validate([
            'is_open' => 'required|boolean',
            'admission_session_name' => 'nullable|string|max:100',
            'application_fee' => 'required|numeric|min:0',
            'platform_fee' => 'nullable|numeric|min:0',
            'require_payment' => 'required|boolean',
            'available_classes' => 'nullable|array',
            'instructions' => 'nullable|string',
            'requirements' => 'nullable|array',
            'start_date' => 'nullable|date',
            'closing_date' => 'nullable|date',
            'contact_email' => 'nullable|email|max:150',
            'contact_phone' => 'nullable|string|max:50',
            'auto_admit' => 'nullable|boolean',
        ]);

        $settings->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Admission configuration updated successfully.',
            'settings' => $settings->fresh(),
        ]);
    }

    /**
     * List all admission applications for the school.
     */
    public function index(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }
        $schoolId = $school->id;

        $query = SchoolAdmissionApplication::where('school_id', $schoolId)
            ->with(['payment', 'level', 'department', 'enrolledUser']);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('application_number', 'like', "%{$search}%")
                  ->orWhere('firstname', 'like', "%{$search}%")
                  ->orWhere('surname', 'like', "%{$search}%")
                  ->orWhere('parent_name', 'like', "%{$search}%")
                  ->orWhere('parent_phone', 'like', "%{$search}%");
            });
        }

        // Status Filter
        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        // Payment Status Filter
        if ($paymentStatus = $request->input('payment_status')) {
            if ($paymentStatus !== 'all') {
                $query->where('payment_status', $paymentStatus);
            }
        }

        // Class Filter
        if ($className = $request->input('class')) {
            if ($className !== 'all') {
                $query->where('applied_class_name', $className);
            }
        }

        $perPage = max(5, min(100, (int) $request->input('per_page', 15)));
        $applications = $query->latest()->paginate($perPage);

        // Summary Counts
        $counts = [
            'all' => SchoolAdmissionApplication::where('school_id', $schoolId)->count(),
            'submitted' => SchoolAdmissionApplication::where('school_id', $schoolId)->where('status', 'submitted')->count(),
            'under_review' => SchoolAdmissionApplication::where('school_id', $schoolId)->where('status', 'under_review')->count(),
            'admitted' => SchoolAdmissionApplication::where('school_id', $schoolId)->where('status', 'admitted')->count(),
            'enrolled' => SchoolAdmissionApplication::where('school_id', $schoolId)->where('status', 'enrolled')->count(),
            'rejected' => SchoolAdmissionApplication::where('school_id', $schoolId)->where('status', 'rejected')->count(),
            'paid' => SchoolAdmissionApplication::where('school_id', $schoolId)->where('payment_status', 'paid')->count(),
            'pending_payment' => SchoolAdmissionApplication::where('school_id', $schoolId)->where('payment_status', 'pending')->count(),
        ];

        // Total Revenue via Wema
        $totalRevenue = SchoolAdmissionPayment::where('school_id', $schoolId)
            ->where('status', 'successful')
            ->sum('school_amount');

        return response()->json([
            'status' => true,
            'applications' => $applications,
            'counts' => $counts,
            'total_revenue' => (float) $totalRevenue,
        ]);
    }

    /**
     * View single candidate dossier.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $application = SchoolAdmissionApplication::where('school_id', $school->id)
            ->where('id', $id)
            ->with(['payment', 'level', 'department', 'enrolledUser', 'enrolledParent'])
            ->firstOrFail();

        $classes = StudentClass::where('school_id', $school->id)->whereNull('archived_at')->get();
        $sections = Section::where('school_id', $school->id)->whereNull('archived_at')->get();
        $departments = Department::where('school_id', $school->id)->whereNull('archived_at')->get();

        return response()->json([
            'status' => true,
            'application' => $application,
            'classes' => $classes,
            'sections' => $sections,
            'departments' => $departments,
        ]);
    }

    /**
     * Update Candidate Application Status (Under Review, Admitted, Rejected).
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $application = SchoolAdmissionApplication::where('school_id', $school->id)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => 'required|in:submitted,under_review,admitted,rejected',
            'rejection_reason' => 'nullable|string|max:1000',
            'reviewer_notes' => 'nullable|string|max:1000',
        ]);

        $application->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Candidate application status updated to ' . ucfirst($validated['status']),
            'application' => $application->fresh(),
        ]);
    }

    /**
     * 1-Click Candidate Enrollment:
     * Converts an admitted candidate directly into the live Student roster,
     * generates/links the Parent account, and issues login credentials.
     */
    public function enroll(Request $request, int $id): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $application = SchoolAdmissionApplication::where('school_id', $school->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($application->status === 'enrolled' && $application->enrolled_user_id) {
            return response()->json([
                'status' => false,
                'message' => 'This candidate has already been enrolled as a student.',
            ], 422);
        }

        $validated = $request->validate([
            'level_id' => 'required|integer',
            'section_id' => 'required|integer',
            'department_id' => 'nullable|integer',
            'reg_no' => 'nullable|string|max:100',
        ]);

        return DB::transaction(function () use ($school, $application, $validated) {
            // 1. Generate Registration Number
            if (! empty($validated['reg_no'])) {
                $finalRegNo = strtoupper(trim($validated['reg_no']));
                if (User::where('reg_no', $finalRegNo)->where('school_id', $school->id)->exists()) {
                    return response()->json(['message' => "Registration number {$finalRegNo} is already taken."], 422);
                }
            } else {
                $prefix = $school->prefix ?: 'SCH';
                do {
                    $rand = random_int(10000, 99999);
                    $finalRegNo = "{$prefix}/" . date('Y') . "/{$rand}";
                } while (User::where('reg_no', $finalRegNo)->where('school_id', $school->id)->exists());
            }

            // 2. Default Student Password
            $defaultStudentPassword = Str::random(8);

            // 3. Create Student User Record
            $student = new User();
            $student->school_id = $school->id;
            $student->firstname = $application->firstname;
            $student->surname = $application->surname;
            $student->third_name = $application->other_names ?: '';
            $student->username = $finalRegNo;
            $student->reg_no = $finalRegNo;
            $student->dob = $application->dob;
            $student->address = $application->home_address ?: $application->parent_address;
            $student->gender = $application->gender;
            $student->blood_group = $application->blood_group;
            $student->religion = $application->religion;
            $student->nationality = $application->nationality ?: 'Nigerian';
            $student->level_id = $validated['level_id'];
            $student->section_id = $validated['section_id'];
            $student->department_id = $validated['department_id'] ?? null;
            $student->photo = $application->passport_photo;
            $student->role = 'Student';
            $student->password = Hash::make($defaultStudentPassword);
            $student->default_password = $defaultStudentPassword;
            $student->status = 1;
            $student->student_status = 'active';
            $student->save();

            $student->assignRole('student');

            // 4. Create or Find Parent Account
            $parentUser = null;
            $defaultParentPassword = null;
            $parentPhone = trim($application->parent_phone);
            $parentEmail = $application->parent_email ? trim($application->parent_email) : null;

            if ($parentPhone) {
                $parentUser = User::where('school_id', $school->id)
                    ->where(function ($q) use ($parentPhone, $parentEmail) {
                        $q->where('phone', $parentPhone)
                          ->orWhere('phone_number', $parentPhone);
                        if ($parentEmail) {
                            $q->orWhere('email', $parentEmail);
                        }
                    })
                    ->first();
            }

            if (! $parentUser) {
                // Create new Parent account
                $parentNames = explode(' ', trim($application->parent_name), 2);
                $parentFirstname = $parentNames[0] ?? 'Parent';
                $parentSurname = $parentNames[1] ?? $application->surname;
                $defaultParentPassword = Str::random(8);

                $parentUser = new User();
                $parentUser->school_id = $school->id;
                $parentUser->firstname = $parentFirstname;
                $parentUser->surname = $parentSurname;
                $parentUser->email = $parentEmail ?: "parent_{$student->id}@schoolprofit.ng";
                $parentUser->phone = $parentPhone;
                $parentUser->phone_number = $parentPhone;
                $parentUser->address = $application->parent_address;
                $parentUser->role = 'Parent';
                $parentUser->password = Hash::make($defaultParentPassword);
                $parentUser->default_password = $defaultParentPassword;
                $parentUser->status = 1;
                $parentUser->save();

                $parentUser->assignRole('parent');
            }

            // Link parent and student
            ParentStudent::firstOrCreate([
                'parent_id' => $parentUser->id,
                'student_id' => $student->id,
            ]);

            // 5. Update Application record
            $application->update([
                'status' => 'enrolled',
                'enrolled_user_id' => $student->id,
                'enrolled_parent_id' => $parentUser->id,
                'assigned_reg_no' => $finalRegNo,
                'enrolled_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => "Candidate {$application->firstname} {$application->surname} enrolled successfully!",
                'enrollment' => [
                    'student_id' => $student->id,
                    'student_name' => "{$student->firstname} {$student->surname}",
                    'reg_no' => $finalRegNo,
                    'student_password' => $defaultStudentPassword,
                    'parent_name' => "{$parentUser->firstname} {$parentUser->surname}",
                    'parent_phone' => $parentUser->phone,
                    'parent_password' => $defaultParentPassword,
                ],
                'application' => $application->fresh(),
            ]);
        });
    }
}
