<?php

namespace App\Services\Results;

use App\Models\Subject;
use Illuminate\Support\Collection;

class SubjectService
{
    /**
     * Get subjects for a student based on department.
     */
    public function subjectsForDepartment(int $schoolId, ?int $departmentId): Collection
    {
        if (!$departmentId) {
            return collect();
        }

        return Subject::where('school_id', $schoolId)
            ->where('department_id', $departmentId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }
}
