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
        $subjects = $this->subjectsForOfferingScope(
            $schoolId,
            $student->level_id ? (int) $student->level_id : null,
            $student->section_id ? (int) $student->section_id : null,
            $student->department_id ? (int) $student->department_id : null,
            $academicSessionId,
            $termId,
            $student->id ? (int) $student->id : null
        );

        if ($subjects->isNotEmpty()) {
            return $subjects;
        }

        return $this->legacySubjectsForDepartment($schoolId, $student->department_id ? (int) $student->department_id : null);
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

        return $this->formatSubjects($this->preferGeneralSubjects($subjects));
    }

    public function preferGeneralSubjects(Collection $subjects): Collection
    {
        return $subjects
            ->sortBy(fn ($subject) => [
                strtolower(trim((string) $subject->name)),
                empty($subject->department_id) ? 0 : 1,
                (int) $subject->id,
            ])
            ->unique(fn ($subject) => strtolower(trim((string) $subject->name)))
            ->sortBy('name')
            ->values();
    }

    private function legacySubjectsForDepartment(int $schoolId, ?int $departmentId): Collection
    {
        $subjects = Subject::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->when($departmentId, function ($query) use ($departmentId) {
                $query->where(function ($inner) use ($departmentId) {
                    $inner->whereNull('department_id')
                        ->orWhere('department_id', $departmentId);
                });
            }, fn ($query) => $query->whereNull('department_id'))
            ->select('id', 'name', 'subject_id', 'department_id', 'section_id', 'class_id')
            ->orderBy('name')
            ->get();

        return $this->formatSubjects($this->preferGeneralSubjects($subjects));
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