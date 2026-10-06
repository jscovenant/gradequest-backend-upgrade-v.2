<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\SchoolSetting;
use App\Models\Section;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Students\StudentExcelImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SuperAdminSchoolSetupController extends Controller
{
    /**
     * Retrieve complete setup data for a target school:
     * branding, classes, sections, departments, subjects, and student count.
     */
    public function getSetupData(Request $request, $schoolId): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);
        $owner = User::where('school_id', $school->id)
            ->whereIn('role', ['admin', 'owner', 'proprietor', 'Admin', 'principal'])
            ->orderByRaw("CASE WHEN role IN ('admin', 'Admin') THEN 1 WHEN role IN ('owner', 'proprietor') THEN 2 ELSE 3 END")
            ->first();

        $classes = StudentClass::where('school_id', $school->id)
            ->whereNull('archived_at')
            ->with('section')
            ->orderBy('name')
            ->get();

        $sections = Section::where('school_id', $school->id)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        $departments = Department::where('school_id', $school->id)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        $subjects = Subject::where('school_id', $school->id)
            ->whereNull('archived_at')
            ->with(['section', 'department'])
            ->orderBy('name')
            ->get();

        $studentCount = User::where('school_id', $school->id)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->count();

        $curriculumTemplates = $this->getCurriculumDefinitions();

        return response()->json([
            'school' => $school,
            'owner' => $owner,
            'classes' => $classes,
            'sections' => $sections,
            'departments' => $departments,
            'subjects' => $subjects,
            'student_count' => $studentCount,
            'curriculum_templates' => $curriculumTemplates,
        ]);
    }

    /**
     * Update school branding (Logo, prefix, surfix, primary/secondary colors).
     */
    public function updateBranding(Request $request, $schoolId): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);

        $validated = $request->validate([
            'prefix' => 'nullable|string|max:20',
            'surfix' => 'nullable|string|max:20',
            'primary_color' => 'nullable|string|max:30',
            'secondary_color' => 'nullable|string|max:30',
            'auto_admission' => 'nullable|boolean',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp,svg|max:4096',
        ]);

        if ($request->has('prefix')) {
            $school->prefix = !empty($validated['prefix']) ? strtoupper(trim($validated['prefix'])) : null;
        }

        if ($request->has('surfix')) {
            $school->surfix = !empty($validated['surfix']) ? trim($validated['surfix']) : null;
        }

        if (!empty($validated['primary_color'])) {
            $school->primary_color = trim($validated['primary_color']);
        }

        if (!empty($validated['secondary_color'])) {
            $school->secondary_color = trim($validated['secondary_color']);
        }

        if ($request->has('auto_admission')) {
            $school->auto_admission = $request->boolean('auto_admission');
        }

        if ($request->hasFile('logo')) {
            $logoFile = $request->file('logo');
            $destPath = public_path('uploads/schools');
            if (!file_exists($destPath)) {
                @mkdir($destPath, 0755, true);
            }
            $logoName = 'logo_' . time() . '_' . Str::slug(pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $logoFile->getClientOriginalExtension();
            $logoFile->move($destPath, $logoName);
            $school->logo = 'uploads/schools/' . $logoName;
        }

        $school->save();

        // Write activity log
        $actor = $request->user();
        ActivityLog::create([
            'user_id' => $actor?->id,
            'school_id' => $school->id,
            'action' => 'school_branding_update',
            'description' => "Updated branding & identity (Prefix: '{$school->prefix}', Surfix: '{$school->surfix}', Colors: {$school->primary_color}/{$school->secondary_color}) for '{$school->school_name}'.",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'School branding and identity updated successfully.',
            'school' => $school,
        ]);
    }

    /**
     * Apply default academic preset (JSS 1-3, SSS 1-3, Pry 1-6, Junior/Senior sections, etc.)
     */
    public function applyPreset(Request $request, $schoolId): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);

        StudentExcelImportService::provisionDefaultAcademicStructure($school->id);

        $classes = StudentClass::where('school_id', $school->id)->whereNull('archived_at')->with('section')->orderBy('name')->get();
        $sections = Section::where('school_id', $school->id)->whereNull('archived_at')->orderBy('name')->get();
        $departments = Department::where('school_id', $school->id)->whereNull('archived_at')->orderBy('name')->get();

        return response()->json([
            'message' => 'Default academic structure provisioned successfully.',
            'classes' => $classes,
            'sections' => $sections,
            'departments' => $departments,
        ]);
    }

    /**
     * Add Section to school.
     */
    public function storeSection(Request $request, $schoolId): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);
        $request->validate(['name' => 'required|string|max:100']);

        $name = trim($request->name);
        $section = Section::firstOrCreate([
            'school_id' => $school->id,
            'name' => $name,
        ]);

        $sections = Section::where('school_id', $school->id)->whereNull('archived_at')->orderBy('name')->get();

        return response()->json([
            'message' => "Section '{$section->name}' saved successfully.",
            'section' => $section,
            'sections' => $sections,
        ], 201);
    }

    /**
     * Remove / Archive Section from school.
     */
    public function deleteSection(Request $request, $schoolId, $sectionId): JsonResponse
    {
        $section = Section::where('school_id', $schoolId)->findOrFail($sectionId);
        $section->delete();

        $sections = Section::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('name')->get();

        return response()->json([
            'message' => 'Section deleted successfully.',
            'sections' => $sections,
        ]);
    }

    /**
     * Add Department to school.
     */
    public function storeDepartment(Request $request, $schoolId): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);
        $request->validate(['name' => 'required|string|max:100']);

        $name = trim($request->name);
        $department = Department::firstOrCreate([
            'school_id' => $school->id,
            'name' => $name,
        ]);

        $departments = Department::where('school_id', $school->id)->whereNull('archived_at')->orderBy('name')->get();

        return response()->json([
            'message' => "Department '{$department->name}' saved successfully.",
            'department' => $department,
            'departments' => $departments,
        ], 201);
    }

    /**
     * Remove / Archive Department from school.
     */
    public function deleteDepartment(Request $request, $schoolId, $departmentId): JsonResponse
    {
        $department = Department::where('school_id', $schoolId)->findOrFail($departmentId);
        $department->delete();

        $departments = Department::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('name')->get();

        return response()->json([
            'message' => 'Department deleted successfully.',
            'departments' => $departments,
        ]);
    }

    /**
     * Add Class to school.
     */
    public function storeClass(Request $request, $schoolId): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);
        $request->validate([
            'name' => 'required|string|max:100',
            'section_id' => 'nullable|integer',
        ]);

        $name = trim($request->name);
        $class = StudentClass::firstOrCreate(
            ['school_id' => $school->id, 'name' => $name],
            ['section_id' => $request->section_id ?: null]
        );

        if ($request->filled('section_id')) {
            $class->section_id = (int) $request->section_id;
            $class->save();
        }

        $classes = StudentClass::where('school_id', $school->id)->whereNull('archived_at')->with('section')->orderBy('name')->get();

        return response()->json([
            'message' => "Class '{$class->name}' saved successfully.",
            'class' => $class,
            'classes' => $classes,
        ], 201);
    }

    /**
     * Remove / Archive Class from school.
     */
    public function deleteClass(Request $request, $schoolId, $classId): JsonResponse
    {
        $class = StudentClass::where('school_id', $schoolId)->findOrFail($classId);
        $class->delete();

        $classes = StudentClass::where('school_id', $schoolId)->whereNull('archived_at')->with('section')->orderBy('name')->get();

        return response()->json([
            'message' => 'Class deleted successfully.',
            'classes' => $classes,
        ]);
    }

    /**
     * Add single Subject to school.
     */
    public function storeSubject(Request $request, $schoolId): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);
        $request->validate([
            'name' => 'required|string|max:150',
            'subject_code' => 'nullable|string|max:30',
            'section_id' => 'nullable|integer',
            'department_id' => 'nullable|integer',
        ]);

        $name = trim($request->name);
        $code = !empty($request->subject_code)
            ? strtoupper(trim($request->subject_code))
            : strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 4));

        $subject = Subject::firstOrCreate(
            ['school_id' => $school->id, 'name' => $name],
            [
                'subject_id' => $code,
                'section_id' => $request->section_id ?: null,
                'department_id' => $request->department_id ?: null,
            ]
        );

        $subjects = Subject::where('school_id', $school->id)->whereNull('archived_at')->with(['section', 'department'])->orderBy('name')->get();

        return response()->json([
            'message' => "Subject '{$subject->name}' saved successfully.",
            'subject' => $subject,
            'subjects' => $subjects,
        ], 201);
    }

    /**
     * Bulk Seed Curriculum Subjects into target school.
     */
    public function seedCurriculum(Request $request, $schoolId): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);
        $category = $request->input('category', 'all'); // 'primary', 'junior', 'senior_core', 'all'
        $definitions = $this->getCurriculumDefinitions();

        $sections = Section::where('school_id', $school->id)->whereNull('archived_at')->get();
        $primarySection = $sections->first(fn ($s) => stripos($s->name, 'prim') !== false || stripos($s->name, 'nurs') !== false || stripos($s->name, 'basic') !== false);
        $juniorSection = $sections->first(fn ($s) => stripos($s->name, 'jun') !== false || stripos($s->name, 'jss') !== false);
        $seniorSection = $sections->first(fn ($s) => stripos($s->name, 'sen') !== false || stripos($s->name, 'sss') !== false);

        $departments = Department::where('school_id', $school->id)->whereNull('archived_at')->get();
        $scienceDept = $departments->first(fn ($d) => stripos($d->name, 'scien') !== false);
        $artsDept = $departments->first(fn ($d) => stripos($d->name, 'art') !== false || stripos($d->name, 'human') !== false);
        $commercialDept = $departments->first(fn ($d) => stripos($d->name, 'comm') !== false || stripos($d->name, 'bus') !== false);

        $toProcess = [];
        if ($category === 'all') {
            foreach ($definitions as $catList) {
                $toProcess = array_merge($toProcess, $catList);
            }
        } elseif (isset($definitions[$category])) {
            $toProcess = $definitions[$category];
        }

        $createdCount = 0;
        foreach ($toProcess as $item) {
            $secId = null;
            if (($item['section_tag'] ?? '') === 'primary') $secId = $primarySection?->id;
            elseif (($item['section_tag'] ?? '') === 'junior') $secId = $juniorSection?->id;
            elseif (($item['section_tag'] ?? '') === 'senior') $secId = $seniorSection?->id;

            $deptId = null;
            if (($item['dept_tag'] ?? '') === 'science') $deptId = $scienceDept?->id;
            elseif (($item['dept_tag'] ?? '') === 'arts') $deptId = $artsDept?->id;
            elseif (($item['dept_tag'] ?? '') === 'commercial') $deptId = $commercialDept?->id;

            $existing = Subject::where('school_id', $school->id)->where('name', $item['name'])->first();
            if (!$existing) {
                Subject::create([
                    'school_id' => $school->id,
                    'name' => $item['name'],
                    'subject_id' => $item['code'],
                    'section_id' => $secId,
                    'department_id' => $deptId,
                ]);
                $createdCount++;
            }
        }

        $subjects = Subject::where('school_id', $school->id)->whereNull('archived_at')->with(['section', 'department'])->orderBy('name')->get();

        return response()->json([
            'message' => "Successfully seeded {$createdCount} subjects for {$school->school_name}.",
            'created_count' => $createdCount,
            'subjects' => $subjects,
        ]);
    }

    /**
     * Delete Subject from school.
     */
    public function deleteSubject(Request $request, $schoolId, $subjectId): JsonResponse
    {
        $subject = Subject::where('school_id', $schoolId)->findOrFail($subjectId);
        $subject->delete();

        $subjects = Subject::where('school_id', $schoolId)->whereNull('archived_at')->with(['section', 'department'])->orderBy('name')->get();

        return response()->json([
            'message' => 'Subject deleted successfully.',
            'subjects' => $subjects,
        ]);
    }

    /**
     * Download Student Excel Template customized for this school.
     */
    public function downloadStudentTemplate(Request $request, $schoolId, StudentExcelImportService $service)
    {
        $school = SchoolSetting::findOrFail($schoolId);
        return $service->template((int) $school->id, 'xlsx');
    }

    /**
     * Preview Student Excel File before importing.
     */
    public function previewStudents(Request $request, $schoolId, StudentExcelImportService $service): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);
        $request->validate(['file' => 'required|file|max:10240']);

        try {
            $preview = $service->preview($request->user(), $request->file('file'), (int) $school->id);
            return response()->json($preview);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Import Students from Excel file directly into target school.
     */
    public function importStudents(Request $request, $schoolId, StudentExcelImportService $service): JsonResponse
    {
        $school = SchoolSetting::findOrFail($schoolId);
        $request->validate(['file' => 'required|file|max:10240']);

        try {
            $result = $service->import($request->user(), $request->file('file'), (int) $school->id);

            $actor = $request->user();
            ActivityLog::create([
                'user_id' => $actor?->id,
                'school_id' => $school->id,
                'action' => 'superadmin_student_import',
                'description' => "Concierge Student Onboarding: Imported {$result['imported']} students into '{$school->school_name}' (ID: {$school->id}).",
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $newCount = User::where('school_id', $school->id)
                ->whereRaw('LOWER(role) = ?', ['student'])
                ->where('status', 1)
                ->count();

            if (($result['imported'] ?? 0) === 0) {
                return response()->json([
                    'message' => $result['message'] ?? 'No valid students could be imported from the file.',
                    'imported' => 0,
                    'total_students' => $newCount,
                    'details' => $result,
                ], 422);
            }

            $successMsg = "Successfully imported {$result['imported']} students for {$school->school_name}.";
            if (!empty($result['skipped_errors'])) {
                $successMsg .= " ({$result['skipped_errors']} invalid row(s) were skipped).";
            }

            return response()->json([
                'message' => $successMsg,
                'imported' => $result['imported'],
                'total_students' => $newCount,
                'details' => $result,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Standard Nigerian Curriculum Definitions.
     */
    private function getCurriculumDefinitions(): array
    {
        return [
            'primary' => [
                ['name' => 'Mathematics', 'code' => 'MTH', 'section_tag' => 'primary'],
                ['name' => 'English Studies', 'code' => 'ENG', 'section_tag' => 'primary'],
                ['name' => 'Basic Science & Technology', 'code' => 'BST', 'section_tag' => 'primary'],
                ['name' => 'Social Studies', 'code' => 'SOS', 'section_tag' => 'primary'],
                ['name' => 'Civic Education', 'code' => 'CIV', 'section_tag' => 'primary'],
                ['name' => 'Quantitative Reasoning', 'code' => 'QTR', 'section_tag' => 'primary'],
                ['name' => 'Verbal Reasoning', 'code' => 'VRB', 'section_tag' => 'primary'],
                ['name' => 'Christian Religious Studies (CRS)', 'code' => 'CRS', 'section_tag' => 'primary'],
                ['name' => 'Islamic Religious Studies (IRS)', 'code' => 'IRS', 'section_tag' => 'primary'],
                ['name' => 'Cultural & Creative Arts (CCA)', 'code' => 'CCA', 'section_tag' => 'primary'],
                ['name' => 'Physical & Health Education (PHE)', 'code' => 'PHE', 'section_tag' => 'primary'],
                ['name' => 'Agricultural Science', 'code' => 'AGR', 'section_tag' => 'primary'],
            ],
            'junior' => [
                ['name' => 'Mathematics', 'code' => 'MTH', 'section_tag' => 'junior'],
                ['name' => 'English Language', 'code' => 'ENG', 'section_tag' => 'junior'],
                ['name' => 'Basic Science', 'code' => 'BSC', 'section_tag' => 'junior'],
                ['name' => 'Basic Technology', 'code' => 'BTE', 'section_tag' => 'junior'],
                ['name' => 'Civic Education', 'code' => 'CIV', 'section_tag' => 'junior'],
                ['name' => 'Social Studies', 'code' => 'SOS', 'section_tag' => 'junior'],
                ['name' => 'Agricultural Science', 'code' => 'AGR', 'section_tag' => 'junior'],
                ['name' => 'Business Studies', 'code' => 'BUS', 'section_tag' => 'junior'],
                ['name' => 'Home Economics', 'code' => 'HEC', 'section_tag' => 'junior'],
                ['name' => 'Physical & Health Education (PHE)', 'code' => 'PHE', 'section_tag' => 'junior'],
                ['name' => 'Christian Religious Studies (CRS)', 'code' => 'CRS', 'section_tag' => 'junior'],
                ['name' => 'Islamic Religious Studies (IRS)', 'code' => 'IRS', 'section_tag' => 'junior'],
                ['name' => 'Computer Studies / ICT', 'code' => 'ICT', 'section_tag' => 'junior'],
            ],
            'senior_core' => [
                ['name' => 'English Language', 'code' => 'ENG', 'section_tag' => 'senior'],
                ['name' => 'General Mathematics', 'code' => 'MTH', 'section_tag' => 'senior'],
                ['name' => 'Civic Education', 'code' => 'CIV', 'section_tag' => 'senior'],
                ['name' => 'Economics', 'code' => 'ECO', 'section_tag' => 'senior'],
                ['name' => 'Data Processing', 'code' => 'DPR', 'section_tag' => 'senior'],
                ['name' => 'Biology', 'code' => 'BIO', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Chemistry', 'code' => 'CHM', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Physics', 'code' => 'PHY', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Further Mathematics', 'code' => 'FMT', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Agricultural Science', 'code' => 'AGR', 'section_tag' => 'senior', 'dept_tag' => 'science'],
                ['name' => 'Government', 'code' => 'GOV', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Literature in English', 'code' => 'LIT', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Christian Religious Studies (CRS)', 'code' => 'CRS', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Islamic Studies (IRS)', 'code' => 'IRS', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'History', 'code' => 'HIS', 'section_tag' => 'senior', 'dept_tag' => 'arts'],
                ['name' => 'Financial Accounting', 'code' => 'ACC', 'section_tag' => 'senior', 'dept_tag' => 'commercial'],
                ['name' => 'Commerce', 'code' => 'COM', 'section_tag' => 'senior', 'dept_tag' => 'commercial'],
                ['name' => 'Marketing', 'code' => 'MKT', 'section_tag' => 'senior', 'dept_tag' => 'commercial'],
            ],
        ];
    }
}
