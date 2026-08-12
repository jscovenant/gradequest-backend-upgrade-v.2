<?php

namespace App\Services\Results;

use App\Models\Subject;
use Illuminate\Support\Collection;

class SubjectService
{
    /**
     * Get subjects for a student based on department.
     *
     * Subjects with NULL department_id are general subjects and apply to every
     * department. If a school still has an old department-specific duplicate
     * with the same name, prefer the general subject for new result entry.
     */
    public function subjectsForDepartment(int $schoolId, ?int $departmentId): Collection
    {
        $subjects = Subject::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->when($departmentId, function ($query) use ($departmentId) {
                $query->where(function ($inner) use ($departmentId) {
                    $inner->whereNull('department_id')
                        ->orWhere('department_id', $departmentId);
                });
            }, fn ($query) => $query->whereNull('department_id'))
            ->select('id', 'name', 'department_id')
            ->orderBy('name')
            ->get();

        return $this->preferGeneralSubjects($subjects)
            ->map(fn ($subject) => (object) [
                'id' => (int) $subject->id,
                'name' => (string) $subject->name,
            ])
            ->values();
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
}
