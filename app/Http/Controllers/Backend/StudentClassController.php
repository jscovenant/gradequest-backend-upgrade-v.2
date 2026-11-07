<?php

namespace App\Http\Controllers\Backend;

use App\Models\Section;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\StudentClass;
use App\Services\AcademicSetupArchiveService;
use Illuminate\Support\Facades\Auth;

class StudentClassController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(["message" => "User not authenticated!"], 401);
        }

        $levels = StudentClass::where('school_id', $user->school_id)
            ->with('section:id,name')
            ->withCount(['students' => function ($q) use ($user) {
                $q->where('school_id', $user->school_id)
                  ->where('role', 'Student')
                  ->where('student_status', 'active');
            }])
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'))
            ->when(!$request->boolean('archived') && !$request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('name', 'asc')
            ->get();

        return response()->json($levels);
    }

    public function store(Request $request)
    {
        $auth = Auth::user();

        // Handle Batch Arms Generation (e.g. Base: JSS1 + Arms: ["Magenta", "Gold", "Diamond"])
        if ($request->filled('batch_arms') && is_array($request->batch_arms)) {
            $request->validate([
                'base_name' => 'required|string|max:50',
                'batch_arms' => 'required|array|min:1',
                'batch_arms.*' => 'required|string|max:50',
                'description' => 'nullable|string|max:255',
                'section_id' => 'nullable|exists:sections,id'
            ]);

            $baseName = strtoupper(trim($request->base_name));
            $createdCount = 0;
            $skippedCount = 0;

            foreach ($request->batch_arms as $arm) {
                $armClean = strtoupper(trim($arm));
                if (empty($armClean)) continue;

                $fullName = trim("{$baseName} {$armClean}");

                if (StudentClass::where('name', $fullName)
                    ->where('school_id', $auth->school_id)
                    ->whereNull('archived_at')
                    ->exists()) {
                    $skippedCount++;
                    continue;
                }

                $class = new StudentClass();
                $class->name = $fullName;
                $class->class_group = $armClean;
                $class->description = $request->description;
                $class->school_id = $auth->school_id;
                $class->section_id = $request->section_id;
                $class->save();
                $createdCount++;
            }

            $msg = "{$createdCount} class arm(s) created successfully.";
            if ($skippedCount > 0) {
                $msg .= " ({$skippedCount} arm(s) already existed and were skipped).";
            }

            return response()->json(['message' => $msg, 'created_count' => $createdCount], 201);
        }

        // Single Class Creation
        $request->validate([
            'name' => 'required|string|max:100',
            'class_group' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'section_id' => 'nullable|exists:sections,id'
        ]);

        $name = strtoupper(trim($request->name));
        $classGroup = $request->filled('class_group') ? strtoupper(trim($request->class_group)) : null;

        if (StudentClass::where('name', $name)
            ->where('school_id', $auth->school_id)
            ->whereNull('archived_at')
            ->exists()) {
            return response()->json(['message' => "Class '{$name}' already exists."], 409);
        }

        if ($request->filled('section_id') && !Section::where('school_id', $auth->school_id)->whereNull('archived_at')->where('id', $request->section_id)->exists()) {
            return response()->json(['message' => 'Selected section is archived or unavailable.'], 422);
        }

        $class = new StudentClass();
        $class->name = $name;
        $class->class_group = $classGroup;
        $class->description = $request->description;
        $class->school_id = $auth->school_id;
        $class->section_id = $request->section_id;
        $class->save();

        return response()->json(['message' => 'Class created successfully', 'class' => $class->load('section:id,name')], 201);
    }

    public function update(Request $request, $id)
    {
        $schoolId = Auth::user()->school_id;

        $request->validate([
            'name' => 'required|string|max:100',
            'class_group' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'section_id' => 'nullable|exists:sections,id'
        ]);

        $name = strtoupper(trim($request->name));
        $classGroup = $request->filled('class_group') ? strtoupper(trim($request->class_group)) : null;

        $exists = StudentClass::where('name', $name)
            ->where('school_id', $schoolId)
            ->where('id', '!=', $id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json(['message' => "A class named '{$name}' is already saved in active classes."], 422);
        }

        if ($request->filled('section_id') && !Section::where('school_id', $schoolId)->whereNull('archived_at')->where('id', $request->section_id)->exists()) {
            return response()->json(['message' => 'Selected section is archived or unavailable.'], 422);
        }

        $level = StudentClass::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->findOrFail($id);

        $level->update([
            'name' => $name,
            'class_group' => $classGroup,
            'description' => $request->description,
            'section_id' => $request->section_id
        ]);

        return response()->json(['message' => 'Class updated successfully', 'class' => $level->load('section:id,name')]);
    }

    public function show($id)
    {
        $level = StudentClass::where('school_id', Auth::user()->school_id)
            ->with('section:id,name')
            ->withCount(['students' => function ($q) {
                $q->where('role', 'Student')->where('student_status', 'active');
            }])
            ->whereNull('archived_at')
            ->findOrFail($id);

        return response()->json($level);
    }

    public function destroy($id)
    {
        $schoolId = Auth::user()->school_id;

        $level = StudentClass::where('id', $id)->where('school_id', $schoolId)->first();

        if (!$level) {
            return response()->json(['message' => 'Class level not found'], 404);
        }

        $usedInResults = app(AcademicSetupArchiveService::class)->classHasResultRecords($level);

        $level->forceFill(['archived_at' => now()])->save();

        return response()->json([
            'message' => $usedInResults
                ? 'This class has historical academic records. It has been archived safely and hidden from future forms.'
                : 'Class archived successfully.',
            'archived' => true,
            'used_in_results' => $usedInResults,
        ]);
    }

    public function restore($id)
    {
        $schoolId = Auth::user()->school_id;

        $level = StudentClass::where('id', $id)
            ->where('school_id', $schoolId)
            ->whereNotNull('archived_at')
            ->first();

        if (!$level) {
            return response()->json(['message' => 'Archived class not found'], 404);
        }

        $exists = StudentClass::where('name', $level->name)
            ->where('school_id', $schoolId)
            ->where('id', '!=', $level->id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'An active class with this name already exists. Rename it before restoring.'], 422);
        }

        $level->forceFill(['archived_at' => null])->save();

        return response()->json([
            'message' => 'Class restored successfully.',
            'archived' => false,
        ]);
    }

    /**
     * Ensure only Proprietor, Principal, Operator, or SuperAdmin can manage CBT class settings.
     */
    private function ensureCanManageCbtSettings($user): void
    {
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        $isAllowed = $user->isProprietor() 
            || $user->isPrincipal() 
            || $user->isOperator() 
            || $user->isSuperAdminUser()
            || in_array(strtolower(trim((string) $user->role)), ['owner', 'proprietor', 'principal', 'operator', 'admin', 'superadmin'], true);

        abort_unless($isAllowed, 403, 'Unauthorized. Only Proprietor, Principal, or School Operator can access and manage class-level CBT settings.');
    }

    /**
     * Retrieve class-level CBT status and platform fee breakdown.
     */
    public function cbtSettings(Request $request)
    {
        $user = $request->user();
        $this->ensureCanManageCbtSettings($user);
        $schoolId = (int) $user->school_id;

        $cbtAccess = app(\App\Services\CbtAccessService::class);
        $policyService = app(\App\Services\SchoolFeeAccessPolicyService::class);

        $isFullCbt = $cbtAccess->hasFullCbtPackage($schoolId);
        $policy = $policyService->policyForSchool($schoolId);

        $cbtRate = (float) ($policy['standard_cbt_tier_price'] ?? ($policy['platform_fee_amount'] ?? 1000.00));
        $basicRate = (float) ($policy['basic_tier_price'] ?? 500.00);
        if ($basicRate < 500.00) {
            $basicRate = 500.00;
        }

        $disabledClassIds = array_map('intval', (array) ($policy['cbt_disabled_class_ids'] ?? []));

        $classes = StudentClass::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->with('section:id,name')
            ->withCount(['students' => function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId)
                  ->where('role', 'Student')
                  ->where('student_status', 'active');
            }])
            ->orderBy('name', 'asc')
            ->get();

        $classItems = $classes->map(function ($class) use ($isFullCbt, $disabledClassIds, $cbtRate, $basicRate) {
            $cbtEnabled = $isFullCbt && $class->isCbtEnabled($disabledClassIds);

            $rate = $cbtEnabled ? $cbtRate : $basicRate;
            $studentCount = (int) $class->students_count;
            $totalClassFee = $studentCount * $rate;

            return [
                'id' => $class->id,
                'name' => $class->name,
                'class_group' => $class->class_group,
                'section_id' => $class->section_id,
                'section_name' => $class->section?->name,
                'cbt_enabled' => $cbtEnabled,
                'student_count' => $studentCount,
                'platform_fee_per_student' => $rate,
                'total_platform_fee' => $totalClassFee,
                'package_label' => $cbtEnabled ? 'Full CBT Package' : 'Basic Plan (Results Only)',
            ];
        });

        $cbtClassesCount = $classItems->where('cbt_enabled', true)->count();
        $basicClassesCount = $classItems->where('cbt_enabled', false)->count();
        $cbtStudentsCount = $classItems->where('cbt_enabled', true)->sum('student_count');
        $basicStudentsCount = $classItems->where('cbt_enabled', false)->sum('student_count');
        $projectedTotal = $classItems->sum('total_platform_fee');

        return response()->json([
            'is_full_cbt_package' => $isFullCbt,
            'active_edition_tier' => $policy['active_edition_tier'] ?? 'standard_cbt',
            'cbt_rate_per_student' => $cbtRate,
            'basic_rate_per_student' => $basicRate,
            'summary' => [
                'total_classes' => $classItems->count(),
                'cbt_classes_count' => $cbtClassesCount,
                'basic_classes_count' => $basicClassesCount,
                'total_students' => $cbtStudentsCount + $basicStudentsCount,
                'cbt_students_count' => $cbtStudentsCount,
                'basic_students_count' => $basicStudentsCount,
                'projected_total_fee' => $projectedTotal,
                'has_mixed_structure' => $cbtClassesCount > 0 && $basicClassesCount > 0,
            ],
            'classes' => $classItems,
        ]);
    }

    /**
     * Bulk update class-level CBT settings.
     */
    public function updateCbtSettings(Request $request)
    {
        $user = $request->user();
        $this->ensureCanManageCbtSettings($user);
        $schoolId = (int) $user->school_id;

        $cbtAccess = app(\App\Services\CbtAccessService::class);
        abort_unless(
            $cbtAccess->hasFullCbtPackage($schoolId),
            422,
            'Class-level CBT control is only available for schools on the Full CBT Package. Your school is currently on the Basic Result Plan.'
        );

        $validated = $request->validate([
            'classes' => 'required|array|min:1',
            'classes.*.id' => 'required|integer|exists:student_classes,id',
            'classes.*.cbt_enabled' => 'required|boolean',
        ]);

        $school = \App\Models\SchoolSetting::findOrFail($schoolId);
        $rawPolicy = $school->fee_access_policy;
        $policy = is_array($rawPolicy) ? $rawPolicy : (json_decode((string) $rawPolicy, true) ?: []);
        $disabledIds = array_map('intval', (array) ($policy['cbt_disabled_class_ids'] ?? []));

        foreach ($validated['classes'] as $item) {
            $classId = (int) $item['id'];
            $enabled = (bool) $item['cbt_enabled'];

            $class = StudentClass::where('school_id', $schoolId)->find($classId);
            if ($class) {
                $class->forceFill(['cbt_enabled' => $enabled])->save();
            }

            if (! $enabled) {
                if (! in_array($classId, $disabledIds, true)) {
                    $disabledIds[] = $classId;
                }
            } else {
                $disabledIds = array_values(array_filter($disabledIds, fn ($id) => $id !== $classId));
            }
        }

        $policy['cbt_disabled_class_ids'] = array_values(array_unique($disabledIds));
        $school->forceFill(['fee_access_policy' => $policy])->save();

        return response()->json([
            'message' => 'Class-level CBT settings updated successfully. Platform fee and examination access have been adjusted.',
            'cbt_disabled_class_ids' => $policy['cbt_disabled_class_ids'],
        ]);
    }

    /**
     * Single class toggle for CBT.
     */
    public function toggleCbt(Request $request, $id)
    {
        $user = $request->user();
        $this->ensureCanManageCbtSettings($user);
        $schoolId = (int) $user->school_id;

        $cbtAccess = app(\App\Services\CbtAccessService::class);
        abort_unless(
            $cbtAccess->hasFullCbtPackage($schoolId),
            422,
            'Class-level CBT control is only available for schools on the Full CBT Package. Your school is currently on the Basic Result Plan.'
        );

        $class = StudentClass::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->findOrFail($id);

        $school = \App\Models\SchoolSetting::findOrFail($schoolId);
        $rawPolicy = $school->fee_access_policy;
        $policy = is_array($rawPolicy) ? $rawPolicy : (json_decode((string) $rawPolicy, true) ?: []);
        $disabledIds = array_map('intval', (array) ($policy['cbt_disabled_class_ids'] ?? []));

        $currentEnabled = $class->isCbtEnabled($disabledIds);
        $enabled = $request->has('cbt_enabled')
            ? $request->boolean('cbt_enabled')
            : ! $currentEnabled;

        $class->forceFill(['cbt_enabled' => $enabled])->save();

        if (! $enabled) {
            if (! in_array((int) $class->id, $disabledIds, true)) {
                $disabledIds[] = (int) $class->id;
            }
        } else {
            $disabledIds = array_values(array_filter($disabledIds, fn ($dId) => $dId !== (int) $class->id));
        }

        $policy['cbt_disabled_class_ids'] = array_values(array_unique($disabledIds));
        $school->forceFill(['fee_access_policy' => $policy])->save();

        return response()->json([
            'message' => $enabled
                ? "CBT enabled for {$class->name}. Students will be charged at the Full CBT Package rate."
                : "CBT disabled for {$class->name}. Students will automatically be charged at the Basic Plan rate of ₦500.",
            'class_id' => $class->id,
            'cbt_enabled' => $enabled,
            'platform_fee_per_student' => $enabled ? 1000 : 500,
        ]);
    }
}
