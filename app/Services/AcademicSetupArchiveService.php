<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\Section;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AcademicSetupArchiveService
{
    public function subjectHasResultRecords(Subject $subject): bool
    {
        foreach (['subject_results_v2', 'first_term_results', 'second_term_results', 'third_term_results'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('subject_id', $subject->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function termHasResultRecords(Term $term): bool
    {
        if (!Schema::hasTable('result_batches')) {
            return false;
        }

        return DB::table('result_batches')
            ->where('school_id', $term->school_id)
            ->where('term', $term->name)
            ->exists();
    }

    public function sessionHasResultRecords(AcademicSession $session): bool
    {
        if (!Schema::hasTable('result_batches')) {
            return false;
        }

        return DB::table('result_batches')
            ->where('school_id', $session->school_id)
            ->where('session', $session->name)
            ->exists();
    }

    public function departmentHasResultRecords(Department $department): bool
    {
        $subjectIds = Subject::where('department_id', $department->id)
            ->where('school_id', $department->school_id)
            ->pluck('id');

        if ($subjectIds->isEmpty()) {
            return false;
        }

        foreach (['subject_results_v2', 'first_term_results', 'second_term_results', 'third_term_results'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->whereIn('subject_id', $subjectIds)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function classHasResultRecords(StudentClass $class): bool
    {
        foreach (['result_batches', 'averages', 'first_term_results', 'second_term_results', 'third_term_results'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'class_id')) {
                continue;
            }

            $query = DB::table($table)->where('class_id', $class->id);

            if (Schema::hasColumn($table, 'school_id')) {
                $query->where('school_id', $class->school_id);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    public function sectionHasLinkedRecords(Section $section): bool
    {
        foreach ([
            ['users', 'section_id'],
            ['student_classes', 'section_id'],
            ['subjects', 'section_id'],
            ['fee_types', 'section_id'],
            ['averages', 'section_id'],
            ['student_results_v2', 'section_id'],
            ['first_term_results', 'section_id'],
            ['second_term_results', 'section_id'],
            ['third_term_results', 'section_id'],
        ] as [$table, $column]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }

            $query = DB::table($table)->where($column, $section->id);

            if (Schema::hasColumn($table, 'school_id')) {
                $query->where('school_id', $section->school_id);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }
}
