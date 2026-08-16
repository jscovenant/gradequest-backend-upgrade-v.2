<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Section;
use App\Models\StudentClass;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubjectOfferingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;
        $data = $request->validate([
            'level_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'academic_session_id' => ['nullable', 'integer'],
            'term_id' => ['nullable', 'integer'],
        ]);

        $this->authorizeScope($schoolId, $data);

        $offerings = DB::table('subject_offerings')
            ->where('school_id', $schoolId)
            ->where('level_id', $data['level_id'] ?? null)
            ->where('section_id', $data['section_id'] ?? null)
            ->where('department_id', $data['department_id'] ?? null)
            ->where('academic_session_id', $data['academic_session_id'] ?? null)
            ->where('term_id', $data['term_id'] ?? null)
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        return response()->json([
            'subject_ids' => $offerings,
            'scope' => $data,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;
        $data = $request->validate([
            'level_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'academic_session_id' => ['nullable', 'integer'],
            'term_id' => ['nullable', 'integer'],
            'subject_ids' => ['array'],
            'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'is_compulsory' => ['nullable', 'boolean'],
        ]);

        $this->authorizeScope($schoolId, $data);
        $subjectIds = collect($data['subject_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $now = now();

        DB::transaction(function () use ($schoolId, $data, $subjectIds, $now) {
            DB::table('subject_offerings')
                ->where('school_id', $schoolId)
                ->where('level_id', $data['level_id'] ?? null)
                ->where('section_id', $data['section_id'] ?? null)
                ->where('department_id', $data['department_id'] ?? null)
                ->where('academic_session_id', $data['academic_session_id'] ?? null)
                ->where('term_id', $data['term_id'] ?? null)
                ->delete();

            foreach ($subjectIds as $subjectId) {
                DB::table('subject_offerings')->insert([
                    'school_id' => $schoolId,
                    'subject_id' => $subjectId,
                    'level_id' => $data['level_id'] ?? null,
                    'section_id' => $data['section_id'] ?? null,
                    'department_id' => $data['department_id'] ?? null,
                    'academic_session_id' => $data['academic_session_id'] ?? null,
                    'term_id' => $data['term_id'] ?? null,
                    'is_compulsory' => (bool) ($data['is_compulsory'] ?? true),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return response()->json([
            'message' => 'Subject offerings saved successfully.',
            'subject_ids' => $subjectIds,
        ]);
    }

    public function studentOverrides(Request $request, int $studentId): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;
        $studentExists = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('id', $studentId)
            ->where('role', 'Student')
            ->exists();

        abort_unless($studentExists, 404, 'Student not found.');

        $rows = DB::table('student_subject_overrides')
            ->where('school_id', $schoolId)
            ->where('student_id', $studentId)
            ->get();

        return response()->json(['overrides' => $rows]);
    }

    public function saveStudentOverrides(Request $request, int $studentId): JsonResponse
    {
        $schoolId = (int) Auth::user()->school_id;
        $studentExists = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('id', $studentId)
            ->where('role', 'Student')
            ->exists();

        abort_unless($studentExists, 404, 'Student not found.');

        $data = $request->validate([
            'overrides' => ['array'],
            'overrides.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'overrides.*.action' => ['required', 'in:include,exclude'],
            'overrides.*.academic_session_id' => ['nullable', 'integer'],
            'overrides.*.term_id' => ['nullable', 'integer'],
            'overrides.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($schoolId, $studentId, $data) {
            DB::table('student_subject_overrides')
                ->where('school_id', $schoolId)
                ->where('student_id', $studentId)
                ->delete();

            foreach ($data['overrides'] ?? [] as $override) {
                DB::table('student_subject_overrides')->insert([
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'subject_id' => (int) $override['subject_id'],
                    'action' => $override['action'],
                    'academic_session_id' => $override['academic_session_id'] ?? null,
                    'term_id' => $override['term_id'] ?? null,
                    'reason' => $override['reason'] ?? null,
                    'created_by' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Student subject exceptions saved.']);
    }

    private function authorizeScope(int $schoolId, array $data): void
    {
        if (! empty($data['level_id'])) {
            abort_unless(StudentClass::where('school_id', $schoolId)->where('id', $data['level_id'])->exists(), 422, 'Selected class is invalid.');
        }
        if (! empty($data['section_id'])) {
            abort_unless(Section::where('school_id', $schoolId)->where('id', $data['section_id'])->exists(), 422, 'Selected section is invalid.');
        }
        if (! empty($data['department_id'])) {
            abort_unless(Department::where('school_id', $schoolId)->where('id', $data['department_id'])->exists(), 422, 'Selected department is invalid.');
        }
    }
}