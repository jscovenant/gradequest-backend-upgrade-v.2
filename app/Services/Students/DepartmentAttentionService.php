<?php

namespace App\Services\Students;

use App\Models\Department;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\TeacherEnrollment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DepartmentAttentionService
{
    /**
     * Determine if a given class belongs to Senior Secondary school
     */
    public function isSeniorClass(?StudentClass $class): bool
    {
        if (! $class) {
            return false;
        }

        // 1. Check Section Name (e.g. "Senior", "Senior Secondary", "SSS", "Senior High")
        $section = $class->section ?? (
            $class->section_id ? DB::table('sections')->where('id', $class->section_id)->first() : null
        );

        if ($section && ! empty($section->name)) {
            if (preg_match('/senior/i', (string) $section->name)) {
                return true;
            }
        }

        // 2. Check Class Name pattern (e.g. "SS1", "SS 1", "SSS1", "SSS 1", "SS2", "SS3", "Senior 1")
        $className = trim((string) $class->name);
        if (preg_match('/^(ss|sss|senior)/i', $className)) {
            return true;
        }

        // 3. Check if this section has subjects with non-null department_id (indicating departmental streaming)
        if ($class->section_id) {
            $hasDeptSubjects = Subject::where('school_id', $class->school_id)
                ->whereNull('archived_at')
                ->where('section_id', $class->section_id)
                ->whereNotNull('department_id')
                ->exists();

            if ($hasDeptSubjects) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get valid senior departments for a school (e.g. Science, Commercial, Arts/Humanity)
     */
    public function getSeniorDepartments(int $schoolId, ?int $sectionId = null): Collection
    {
        $all = Department::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        // Filter out departments explicitly tagged as junior, basic, primary, or nursery
        $senior = $all->filter(function ($dept) {
            return ! preg_match('/(junior|basic|primary|nursery|kindergarten|creche)/i', (string) $dept->name);
        })->values();

        // If specific section is given, prioritize departments that have active subjects in this section
        if ($sectionId && $senior->isNotEmpty()) {
            $deptIdsWithSubjects = Subject::where('school_id', $schoolId)
                ->whereNull('archived_at')
                ->where('section_id', $sectionId)
                ->whereNotNull('department_id')
                ->pluck('department_id')
                ->unique();

            if ($deptIdsWithSubjects->isNotEmpty()) {
                $filtered = $senior->whereIn('id', $deptIdsWithSubjects)->values();
                if ($filtered->isNotEmpty()) {
                    return $filtered;
                }
            }
        }

        return $senior;
    }

    /**
     * Check whether an assigned department is a junior/basic department or incompatible with senior section
     */
    public function isJuniorOrMismatchedDepartment(?Department $dept, ?StudentClass $class): bool
    {
        if (! $dept) {
            return false;
        }

        $deptName = (string) $dept->name;

        // Pattern match junior/primary/basic names
        if (preg_match('/(junior|basic|primary|nursery|kindergarten|creche)/i', $deptName)) {
            return true;
        }

        // Check cross-section subject mismatch if class has a definitive section
        if ($class && $class->section_id) {
            $deptHasSubjectsInSenior = Subject::where('school_id', $class->school_id)
                ->whereNull('archived_at')
                ->where('department_id', $dept->id)
                ->where('section_id', $class->section_id)
                ->exists();

            $otherDeptsHaveSubjects = Subject::where('school_id', $class->school_id)
                ->whereNull('archived_at')
                ->where('section_id', $class->section_id)
                ->whereNotNull('department_id')
                ->exists();

            if (! $deptHasSubjectsInSenior && $otherDeptsHaveSubjects) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analyze a single student and return department issue details, or null if fine
     */
    public function checkStudent(User $student): ?array
    {
        // Must be active student
        $status = strtolower((string) ($student->student_status ?: ($student->status == 1 ? 'active' : 'inactive')));
        if ($status !== 'active') {
            return null;
        }

        $class = $student->level;
        if (! $class) {
            return null;
        }

        if (! $this->isSeniorClass($class)) {
            return null;
        }

        // Case A: Missing department
        if (empty($student->department_id)) {
            return [
                'needs_attention' => true,
                'issue' => 'unassigned',
                'issue_label' => 'Stream Unassigned',
                'title' => 'Senior Department Unassigned',
                'message' => "Student is enrolled in {$class->name} without a senior department stream (Science, Commercial, or Arts).",
            ];
        }

        // Case B: Mismatched department (e.g. Junior Department carried over into Senior)
        $dept = $student->department;
        if ($this->isJuniorOrMismatchedDepartment($dept, $class)) {
            $deptName = $dept ? $dept->name : 'Unknown';
            return [
                'needs_attention' => true,
                'issue' => 'mismatched',
                'issue_label' => 'Junior Stream Mismatch',
                'title' => 'Junior Department Mismatch',
                'message' => "Student is enrolled in {$class->name} (Senior Secondary) but still holds '{$deptName}' from junior school. Please reassign to a Senior department.",
            ];
        }

        return null;
    }

    /**
     * Loop through students in a school and retrieve all students needing department attention
     */
    public function getStudentsNeedingAttention(int $schoolId, ?int $teacherUserId = null, ?int $limit = null): array
    {
        $query = User::query()
            ->where('school_id', $schoolId)
            ->withRole('student')
            ->where('status', 1)
            ->where(function ($q) {
                $q->whereNull('student_status')->orWhere('student_status', 'active');
            })
            ->whereNotNull('level_id')
            ->with(['level.section', 'department']);

        // Teacher restriction: only students in classes assigned to this teacher
        if ($teacherUserId) {
            $enrolledLevels = TeacherEnrollment::where('user_id', $teacherUserId)
                ->where('school_id', $schoolId)
                ->where('enroll', '1')
                ->pluck('level_id');

            $query->whereIn('level_id', $enrolledLevels);
        }

        $students = $query->get();
        $seniorDepartments = $this->getSeniorDepartments($schoolId);

        $flagged = [];

        foreach ($students as $student) {
            $check = $this->checkStudent($student);
            if (! $check) {
                continue;
            }

            // Name in format: Surname, Firstname Othername
            $parts = array_filter([$student->surname, $student->firstname, $student->third_name]);
            $formattedName = $student->surname
                ? trim($student->surname . ', ' . implode(' ', array_filter([$student->firstname, $student->third_name])))
                : trim($student->firstname . ' ' . $student->surname);

            $flagged[] = [
                'id'                      => (int) $student->id,
                'name'                    => $formattedName,
                'firstname'               => (string) $student->firstname,
                'surname'                 => (string) $student->surname,
                'third_name'              => (string) $student->third_name,
                'reg_no'                  => (string) ($student->reg_no ?: '—'),
                'photo'                   => $student->photo,
                'class_id'                => (int) $student->level_id,
                'class_name'              => (string) ($student->level?->name ?: '—'),
                'section_id'              => $student->level?->section_id ? (int) $student->level->section_id : null,
                'section_name'            => (string) ($student->level?->section?->name ?: 'Senior Secondary'),
                'current_department_id'   => $student->department_id ? (int) $student->department_id : null,
                'current_department_name' => (string) ($student->department?->name ?: 'Unassigned'),
                'issue'                   => $check['issue'],
                'issue_label'             => $check['issue_label'],
                'title'                   => $check['title'],
                'message'                 => $check['message'],
            ];
        }

        $totalCount = count($flagged);

        if ($limit && $limit > 0) {
            $flagged = array_slice($flagged, 0, $limit);
        }

        return [
            'total_count'        => $totalCount,
            'students'           => $flagged,
            'senior_departments' => $seniorDepartments->map(fn ($d) => [
                'id'          => (int) $d->id,
                'name'        => (string) $d->name,
                'description' => (string) ($d->description ?: ''),
            ])->values()->all(),
            'importance_notice'  => 'Students promoted or newly admitted into Senior Secondary (SS1–SS3) must have their academic stream assigned (Science, Commercial, or Arts). Without an assigned senior department, Continuous Assessment score entry only loads general subjects, CBT examinations cannot be scheduled for department courses, and official report cards and broadsheet positions will remain incomplete.',
        ];
    }
}
