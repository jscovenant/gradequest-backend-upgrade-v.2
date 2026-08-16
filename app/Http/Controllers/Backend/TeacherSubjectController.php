<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherSubjectController extends Controller
{
    public function workspace(): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;

        return response()->json([
            'teachers' => $this->teachersPayload($schoolId),
            'subjects' => $this->subjectsQuery($schoolId)->get(),
            'assignments' => $this->assignmentsPayload($schoolId),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json($this->assignmentsPayload((int) Auth::user()->school_id));
    }

    public function store(Request $request): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;

        $data = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:users,id'],
            'subject_ids' => ['present', 'array'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
            'sync' => ['nullable', 'boolean'],
        ]);

        $teacher = $this->teacherQuery($schoolId)->find($data['teacher_id']);
        abort_unless($teacher, 422, 'Selected teacher is invalid for this school.');

        $subjectIds = collect($data['subject_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $eligibleSubjectIds = $this->eligibleSubjectsForTeacher($schoolId, (int) $teacher->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $invalid = $subjectIds->reject(fn ($id) => in_array((int) $id, $eligibleSubjectIds, true))->values();
        abort_if($invalid->isNotEmpty(), 422, 'One or more selected subjects do not match this teacher assigned class or school.');

        DB::transaction(function () use ($teacher, $subjectIds, $data) {
            if ((bool) ($data['sync'] ?? false)) {
                TeacherSubject::query()
                    ->where('teacher_id', (int) $teacher->id)
                    ->whereNotIn('subject_id', $subjectIds->all() ?: [0])
                    ->delete();
            }

            foreach ($subjectIds as $subjectId) {
                TeacherSubject::query()->updateOrCreate([
                    'teacher_id' => (int) $teacher->id,
                    'subject_id' => (int) $subjectId,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Teacher subject assignment saved successfully.',
            'assignments' => $this->assignmentsPayload($schoolId),
        ]);
    }

    public function allTeachers(): JsonResponse
    {
        return response()->json($this->teachersPayload((int) Auth::user()->school_id));
    }

    public function allSubjects(Request $request): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;
        $teacherId = $request->query('teacher_id') ? (int) $request->query('teacher_id') : null;

        if ($teacherId) {
            $teacher = $this->teacherQuery($schoolId)->find($teacherId);
            abort_unless($teacher, 422, 'Selected teacher is invalid for this school.');

            return response()->json($this->eligibleSubjectsForTeacher($schoolId, $teacherId)->get());
        }

        return response()->json($this->subjectsQuery($schoolId)->get());
    }

    public function destroy($teacher_id, $subject_id): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;
        $teacher = $this->teacherQuery($schoolId)->find($teacher_id);
        abort_unless($teacher, 422, 'Selected teacher is invalid for this school.');

        $subjectExists = Subject::query()
            ->where('school_id', $schoolId)
            ->whereKey((int) $subject_id)
            ->exists();
        abort_unless($subjectExists, 422, 'Selected subject is invalid for this school.');

        $deleted = TeacherSubject::query()
            ->where('teacher_id', (int) $teacher_id)
            ->where('subject_id', (int) $subject_id)
            ->delete();

        abort_unless($deleted > 0, 404, 'Assignment not found.');

        return response()->json(['message' => 'Subject removed from teacher.']);
    }

    private function teacherQuery(int $schoolId)
    {
        return User::query()
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['teacher']);
    }

    private function teachersPayload(int $schoolId)
    {
        $teachers = $this->teacherQuery($schoolId)
            ->with(['teacherEnrollment.level.section'])
            ->select('id', 'firstname', 'surname', 'email', 'reg_no', 'username', 'teacher_status')
            ->orderBy('surname')
            ->orderBy('firstname')
            ->get();

        return $teachers->map(function (User $teacher) use ($schoolId) {
            $assignedClasses = $this->teacherAssignedClasses($schoolId, (int) $teacher->id);

            return [
                'id' => (int) $teacher->id,
                'firstname' => $teacher->firstname,
                'surname' => $teacher->surname,
                'email' => $teacher->email,
                'reg_no' => $teacher->reg_no,
                'username' => $teacher->username,
                'teacher_status' => $teacher->teacher_status,
                'assigned_classes' => $assignedClasses,
            ];
        })->values();
    }

    private function assignmentsPayload(int $schoolId)
    {
        return TeacherSubject::query()
            ->join('users as t', 't.id', '=', 'teacher_subjects.teacher_id')
            ->join('subjects as s', 's.id', '=', 'teacher_subjects.subject_id')
            ->leftJoin('sections as sec', 'sec.id', '=', 's.section_id')
            ->leftJoin('departments as dep', 'dep.id', '=', 's.department_id')
            ->where('t.school_id', $schoolId)
            ->where('s.school_id', $schoolId)
            ->whereRaw('LOWER(t.role) = ?', ['teacher'])
            ->select([
                'teacher_subjects.teacher_id',
                'teacher_subjects.subject_id',
                't.firstname as teacher_firstname',
                't.surname as teacher_surname',
                's.name as subject_name',
                's.section_id',
                's.department_id',
                's.class_id',
                'sec.name as section_name',
                'dep.name as department_name',
            ])
            ->orderBy('t.surname')
            ->orderBy('s.name')
            ->get()
            ->map(fn ($row) => [
                'teacher_id' => (int) $row->teacher_id,
                'subject_id' => (int) $row->subject_id,
                'teacher' => [
                    'id' => (int) $row->teacher_id,
                    'firstname' => $row->teacher_firstname,
                    'surname' => $row->teacher_surname,
                ],
                'subject' => [
                    'id' => (int) $row->subject_id,
                    'name' => $row->subject_name,
                    'section_id' => $row->section_id ? (int) $row->section_id : null,
                    'department_id' => $row->department_id ? (int) $row->department_id : null,
                    'class_id' => $row->class_id ? (int) $row->class_id : null,
                    'section_name' => $row->section_name,
                    'department_name' => $row->department_name,
                ],
            ])->values();
    }

    private function subjectsQuery(int $schoolId)
    {
        return Subject::query()
            ->leftJoin('sections as sec', 'sec.id', '=', 'subjects.section_id')
            ->leftJoin('departments as dep', 'dep.id', '=', 'subjects.department_id')
            ->where('subjects.school_id', $schoolId)
            ->whereNull('subjects.archived_at')
            ->select([
                'subjects.id',
                'subjects.name',
                'subjects.section_id',
                'subjects.department_id',
                'subjects.class_id',
                'sec.name as section_name',
                'dep.name as department_name',
            ])
            ->orderBy('subjects.name');
    }

    private function eligibleSubjectsForTeacher(int $schoolId, int $teacherId)
    {
        $assignedClasses = $this->teacherAssignedClasses($schoolId, $teacherId);
        $levelIds = collect($assignedClasses)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $sectionIds = collect($assignedClasses)->pluck('section_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $query = $this->subjectsQuery($schoolId);

        if (! empty($sectionIds)) {
            $query->where(function ($inner) use ($sectionIds) {
                $inner->whereNull('subjects.section_id')->orWhereIn('subjects.section_id', $sectionIds);
            });
        }

        if (! empty($levelIds) && Schema::hasColumn('subjects', 'class_id')) {
            $query->where(function ($inner) use ($levelIds) {
                $inner->whereNull('subjects.class_id')->orWhereIn('subjects.class_id', $levelIds)->orWhere('subjects.class_id', 0);
            });
        }

        return $query;
    }

    private function teacherAssignedClasses(int $schoolId, int $teacherId): array
    {
        if (! Schema::hasTable('teacher_enrollments')) {
            return [];
        }

        $query = DB::table('teacher_enrollments as te')
            ->join('student_classes as c', 'c.id', '=', 'te.level_id')
            ->leftJoin('sections as sec', 'sec.id', '=', 'c.section_id')
            ->where('te.enroll', 1)
            ->where('c.school_id', $schoolId);

        if (Schema::hasColumn('teacher_enrollments', 'school_id')) {
            $query->where('te.school_id', $schoolId);
        }

        $query->where(function ($inner) use ($teacherId) {
            $inner->where('te.user_id', $teacherId);
            if (Schema::hasColumn('teacher_enrollments', 'teacher_id')) {
                $inner->orWhere('te.teacher_id', $teacherId);
            }
        });

        return $query
            ->select('c.id', 'c.name', 'c.section_id', 'sec.name as section_name')
            ->orderBy('c.name')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'section_id' => $row->section_id ? (int) $row->section_id : null,
                'section_name' => $row->section_name,
            ])
            ->values()
            ->all();
    }
}
