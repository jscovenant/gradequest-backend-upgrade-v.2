<?php

namespace App\Services\Results;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubjectService
{
    /**
     * Legacy-compatible department resolver.
     * New code should prefer subjectsForStudent() because it understands class,
     * section, department, academic period, and individual student exceptions.
     */
    public function subjectsForDepartment(int $schoolId, ?int $departmentId): Collection
    {
        if (Schema::hasTable('subject_offerings')) {
            $subjects = $this->subjectsForOfferingScope($schoolId, null, null, $departmentId);
            if ($subjects->isNotEmpty()) {
                return $subjects;
            }
        }

        return $this->legacySubjectsForDepartment($schoolId, $departmentId);
    }

    public function subjectsForStudent(User $student, ?int $academicSessionId = null, ?int $termId = null): Collection
    {
        $schoolId = (int) $student->school_id;
        $class = $student->level ?? \App\Models\StudentClass::find($student->level_id);

        $effectiveSectionId = $student->section_id ? (int) $student->section_id : null;
        if ($class && $class->section_id) {
            $secExists = DB::table('sections')->where('school_id', $schoolId)->where('id', $effectiveSectionId)->exists();
            if (! $secExists) {
                $effectiveSectionId = (int) $class->section_id;
            }
        }
        $effectiveDepartmentId = $student->department_id ? (int) $student->department_id : ($class?->department_id ? (int) $class->department_id : null);
        $classId = $student->level_id ? (int) $student->level_id : null;

        $subjects = $this->subjectsForOfferingScope(
            $schoolId,
            $classId,
            $effectiveSectionId,
            $effectiveDepartmentId,
            $academicSessionId,
            $termId,
            $student->id ? (int) $student->id : null
        );

        if ($subjects->isNotEmpty()) {
            return $subjects;
        }

        if ($effectiveDepartmentId) {
            $subjects = $this->subjectsForOfferingScope(
                $schoolId,
                $classId,
                null,
                $effectiveDepartmentId,
                $academicSessionId,
                $termId,
                $student->id ? (int) $student->id : null
            );
            if ($subjects->isNotEmpty()) {
                return $subjects;
            }
        }

        return $this->legacySubjectsForDepartment($schoolId, $effectiveDepartmentId, $classId);
    }

    public function subjectsForOfferingScope(
        int $schoolId,
        ?int $levelId = null,
        ?int $sectionId = null,
        ?int $departmentId = null,
        ?int $academicSessionId = null,
        ?int $termId = null,
        ?int $studentId = null
    ): Collection {
        if (! Schema::hasTable('subject_offerings')) {
            return collect();
        }

        $subjectIds = DB::table('subject_offerings')
            ->where('school_id', $schoolId)
            ->where(function ($query) use ($levelId) {
                $query->whereNull('level_id');
                if ($levelId) $query->orWhere('level_id', $levelId);
            })
            ->where(function ($query) use ($sectionId) {
                $query->whereNull('section_id');
                if ($sectionId) $query->orWhere('section_id', $sectionId);
            })
            ->where(function ($query) use ($departmentId) {
                $query->whereNull('department_id');
                if ($departmentId) $query->orWhere('department_id', $departmentId);
            })
            ->where(function ($query) use ($academicSessionId) {
                $query->whereNull('academic_session_id');
                if ($academicSessionId) $query->orWhere('academic_session_id', $academicSessionId);
            })
            ->where(function ($query) use ($termId) {
                $query->whereNull('term_id');
                if ($termId) $query->orWhere('term_id', $termId);
            })
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($studentId && Schema::hasTable('student_subject_overrides')) {
            $overrides = DB::table('student_subject_overrides')
                ->where('school_id', $schoolId)
                ->where('student_id', $studentId)
                ->where(function ($query) use ($academicSessionId) {
                    $query->whereNull('academic_session_id');
                    if ($academicSessionId) $query->orWhere('academic_session_id', $academicSessionId);
                })
                ->where(function ($query) use ($termId) {
                    $query->whereNull('term_id');
                    if ($termId) $query->orWhere('term_id', $termId);
                })
                ->get(['subject_id', 'action']);

            $includeIds = $overrides->where('action', 'include')->pluck('subject_id')->map(fn ($id) => (int) $id);
            $excludeIds = $overrides->where('action', 'exclude')->pluck('subject_id')->map(fn ($id) => (int) $id);
            $subjectIds = $subjectIds->merge($includeIds)->diff($excludeIds)->unique()->values();
        }

        if ($subjectIds->isEmpty()) {
            return collect();
        }

        $subjects = Subject::query()
            ->where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->whereIn('id', $subjectIds)
            ->select('id', 'name', 'subject_id', 'department_id', 'section_id', 'class_id')
            ->orderBy('name')
            ->get();

        return $this->formatSubjects($this->preferGeneralSubjects($subjects, $departmentId));
    }

    public function canonicalSubjectKey(string $name): string
    {
        $clean = strtolower(trim($name));
        $clean = preg_replace('/[^a-z0-9]/', '', $clean);

        $aliases = [
            'agric' => 'agric_science',
            'agricscience' => 'agric_science',
            'agriculturalscience' => 'agric_science',
            'agriculturescience' => 'agric_science',
            'agricsci' => 'agric_science',
            'crk' => 'crs',
            'crs' => 'crs',
            'christianreligiousstudies' => 'crs',
            'christianreligionstudies' => 'crs',
            'christianreligiousknowledge' => 'crs',
            'christianreligionknowledge' => 'crs',
            'irk' => 'irs',
            'irs' => 'irs',
            'islamicreligiousstudies' => 'irs',
            'islamicreligionstudies' => 'irs',
            'islamicreligiousknowledge' => 'irs',
            'cca' => 'cca',
            'culturalandcreativeart' => 'cca',
            'culturalcreativeart' => 'cca',
            'culturalandcreativearts' => 'cca',
            'culturalcreativearts' => 'cca',
            'creativeart' => 'cca',
            'creativearts' => 'cca',
            'phe' => 'phe',
            'physicalhealtheducation' => 'phe',
            'physicalandhealtheducation' => 'phe',
            'physicaleducation' => 'phe',
            'security' => 'security_education',
            'securityeducation' => 'security_education',
            'civi' => 'civic_education',
            'civic' => 'civic_education',
            'civiceducation' => 'civic_education',
            'french' => 'french',
            'frenchlanguage' => 'french',
            'socialstudies' => 'social_studies',
            'socialstudy' => 'social_studies',
            'socialandcitizenship' => 'social_studies',
            'basictechnology' => 'basic_technology',
            'basictech' => 'basic_technology',
            'introtech' => 'basic_technology',
            'introductorytechnology' => 'basic_technology',
            'basicscience' => 'basic_science',
            'integratedscience' => 'basic_science',
            'homeeconomics' => 'home_economics',
            'english' => 'english_language',
            'englishlanguage' => 'english_language',
            'maths' => 'mathematics',
            'math' => 'mathematics',
            'mathematics' => 'mathematics',
            'businessstudies' => 'business_studies',
            'computer' => 'computer_studies',
            'computerstudies' => 'computer_studies',
            'computerscience' => 'computer_studies',
            'accounting' => 'financial_accounting',
            'financialaccounting' => 'financial_accounting',
            'literature' => 'literature_in_english',
            'literatureinenglish' => 'literature_in_english',
            'garmentmaking' => 'garment_making',
        ];

        return $aliases[$clean] ?? $clean;
    }

    public function preferGeneralSubjects(Collection $subjects, ?int $preferredDepartmentId = null): Collection
    {
        return $subjects
            ->sortBy(function ($subject) use ($preferredDepartmentId) {
                $canon = $this->canonicalSubjectKey((string) $subject->name);
                $deptScore = 1;
                if ($preferredDepartmentId && (int) $subject->department_id === (int) $preferredDepartmentId) {
                    $deptScore = 0; // Highest priority: matches student department
                } elseif (empty($subject->department_id)) {
                    $deptScore = 2; // Second priority: general
                } else {
                    $deptScore = 3;
                }
                return [
                    $canon,
                    $deptScore,
                    (int) $subject->id,
                ];
            })
            ->unique(fn ($subject) => $this->canonicalSubjectKey((string) $subject->name))
            ->sortBy('name')
            ->values();
    }

    private function legacySubjectsForDepartment(int $schoolId, ?int $departmentId, ?int $classId = null): Collection
    {
        $subjects = Subject::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->when($classId, function ($query) use ($classId) {
                $query->where(function ($inner) use ($classId) {
                    $inner->whereNull('class_id')
                        ->orWhere('class_id', $classId);
                });
            })
            ->when($departmentId, function ($query) use ($departmentId) {
                $query->where(function ($inner) use ($departmentId) {
                    $inner->whereNull('department_id')
                        ->orWhere('department_id', $departmentId);
                });
            }, fn ($query) => $query->whereNull('department_id'))
            ->select('id', 'name', 'subject_id', 'department_id', 'section_id', 'class_id')
            ->orderBy('name')
            ->get();

        return $this->formatSubjects($this->preferGeneralSubjects($subjects, $departmentId));
    }

    private function formatSubjects(Collection $subjects): Collection
    {
        return $subjects
            ->map(fn ($subject) => (object) [
                'id' => (int) $subject->id,
                'name' => (string) $subject->name,
                'subject_id' => $subject->subject_id ?? null,
                'department_id' => $subject->department_id ?? null,
                'section_id' => $subject->section_id ?? null,
                'class_id' => $subject->class_id ?? null,
            ])
            ->values();
    }
}