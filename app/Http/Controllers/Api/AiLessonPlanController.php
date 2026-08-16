<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\GeneratedLessonPlan;
use App\Models\LessonNote;
use App\Models\LessonScheme;
use App\Models\Section;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Services\Lessons\AiLessonPlanGeneratorService;
use App\Services\SubscriptionAiCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AiLessonPlanController extends Controller
{
    public function workspace(Request $request): JsonResponse
    {
        $auth = $request->user();
        $schoolId = (int) ($auth->school_id ?? 0);

        return response()->json([
            'schemes' => LessonScheme::query()->where('school_id', $schoolId)->whereNull('archived_at')->latest()->limit(50)->get(),
            'lesson_plans' => GeneratedLessonPlan::query()->where('school_id', $schoolId)->whereNull('archived_at')->latest()->limit(30)->get(),
            'lesson_notes' => LessonNote::query()->where('school_id', $schoolId)->whereNull('archived_at')->latest()->limit(50)->get(),
            'current_period' => $this->currentPeriodPayload($schoolId),
            'academic_scope' => $this->academicScopePayload($auth, $schoolId),
        ]);
    }

    public function storeScheme(Request $request): JsonResponse
    {
        $auth = $request->user();
        $this->authorizeTeacherOrAdmin($auth);

        $data = $request->validate($this->baseAcademicRules() + [
            'subject' => 'required|string|max:150',
            'class' => 'required|string|max:150',
            'term' => 'nullable|string|max:80',
            'curriculum' => 'nullable|string|max:120',
            'title' => 'nullable|string|max:180',
            'content' => 'required|string|max:50000',
            'topics' => 'nullable|array',
        ]);

        $scope = $this->resolveAcademicScope($data, (int) $auth->school_id, $auth);

        $scheme = LessonScheme::query()->create($scope + [
            'school_id' => (int) $auth->school_id,
            'created_by' => $auth->id,
            'subject' => $data['subject'],
            'class_name' => $data['class'],
            'term' => $data['term'] ?? null,
            'curriculum' => $data['curriculum'] ?? null,
            'source' => 'uploaded',
            'title' => $data['title'] ?: $data['subject'] . ' Scheme of Work',
            'topics' => $data['topics'] ?? $this->topicsFromText($data['content']),
            'content' => $data['content'],
            'status' => 'active',
        ]);

        return response()->json(['message' => 'Scheme of work saved.', 'scheme' => $scheme], 201);
    }

    public function generateScheme(Request $request, AiLessonPlanGeneratorService $generator, SubscriptionAiCreditService $credits): JsonResponse
    {
        $auth = $request->user();
        $this->authorizeTeacherOrAdmin($auth);

        $data = $request->validate($this->baseAcademicRules() + [
            'subject' => 'required|string|max:150',
            'class' => 'required|string|max:150',
            'term' => 'required|string|max:80',
            'curriculum' => 'required|string|max:120',
            'weeks' => 'nullable|integer|min:1|max:16',
            'teacher_notes' => 'nullable|string|max:4000',
        ]);

        $schoolId = (int) $auth->school_id;
        $scope = $this->resolveAcademicScope($data, $schoolId, $auth);
        $featureKey = 'ai_scheme_work_generator';
        $creditCost = $credits->costForFeature($featureKey);
        $credits->assertCreditsAvailable($schoolId, $featureKey, $creditCost);

        try {
            $result = $generator->generateScheme($data + ['school_context' => 'Nigerian/African primary and secondary school classroom']);
        } catch (Throwable $exception) {
            $this->logAiUsage($request, $featureKey, 'failed', [], ['error' => $exception->getMessage(), 'input' => $data]);
            return response()->json(['message' => 'Something went wrong while processing this AI request. Please try again later.'], 422);
        }

        $usage = $credits->consumeCredits($schoolId, $featureKey, $creditCost, $this->reference('ai-scheme', $schoolId), $data);
        $schemeData = $result['scheme'];
        $scheme = LessonScheme::query()->create($scope + [
            'school_id' => $schoolId,
            'created_by' => $auth->id,
            'subject' => $schemeData['subject'] ?: $data['subject'],
            'class_name' => $schemeData['class'] ?: $data['class'],
            'term' => $schemeData['term'] ?: $data['term'],
            'curriculum' => $schemeData['curriculum'] ?: $data['curriculum'],
            'source' => 'ai',
            'title' => $schemeData['title'],
            'topics' => $schemeData['topics'],
            'content' => $schemeData['plain_text'] ?: json_encode($schemeData['topics'], JSON_PRETTY_PRINT),
            'status' => 'active',
        ]);

        $this->logAiUsage($request, $featureKey, 'success', $result['usage'] ?? [], ['scheme_id' => $scheme->id], $usage->id, $creditCost);

        return response()->json(['message' => 'Scheme of work generated and saved.', 'scheme' => $scheme, 'ai_credits' => $this->creditPayload($usage, $creditCost)]);
    }

    public function generate(Request $request, AiLessonPlanGeneratorService $generator, SubscriptionAiCreditService $credits): JsonResponse
    {
        $auth = $request->user();
        $this->authorizeTeacherOrAdmin($auth);

        $data = $request->validate($this->baseAcademicRules() + [
            'scheme_id' => 'nullable|exists:lesson_schemes,id',
            'subject' => 'required|string|max:120',
            'class' => 'required|string|max:120',
            'topic' => 'required|string|max:180',
            'duration_minutes' => 'required|integer|min:10|max:240',
            'teacher_notes' => 'nullable|string|max:4000',
        ]);

        $schoolId = (int) ($auth->school_id ?? 0);
        $scope = $this->resolveAcademicScope($data, $schoolId, $auth);
        $featureKey = 'ai_lesson_plan_generator';
        $creditCost = $credits->costForFeature($featureKey);
        $credits->assertCreditsAvailable($schoolId, $featureKey, $creditCost);

        try {
            $result = $generator->generate($data);
        } catch (Throwable $exception) {
            $this->logAiUsage($request, $featureKey, 'failed', [], ['error' => $exception->getMessage(), 'input' => $data]);
            return response()->json(['message' => 'Something went wrong while processing this AI request. Please try again later.'], 422);
        }

        $usage = $credits->consumeCredits($schoolId, $featureKey, $creditCost, $this->reference('ai-lesson-plan', $schoolId), $data);
        $plan = $result['lesson_plan'];
        $record = GeneratedLessonPlan::query()->create($scope + [
            'school_id' => $schoolId,
            'scheme_id' => $data['scheme_id'] ?? null,
            'created_by' => $auth->id,
            'subject' => $plan['subject'] ?: $data['subject'],
            'class_name' => $plan['class'] ?: $data['class'],
            'topic' => $plan['topic'] ?: $data['topic'],
            'duration_minutes' => (int) $plan['duration_minutes'],
            'plan' => $plan,
            'status' => 'draft',
        ]);

        $this->logAiUsage($request, $featureKey, 'success', $result['usage'] ?? [], ['lesson_plan_id' => $record->id], $usage->id, $creditCost);

        return response()->json(['message' => 'Lesson plan generated and saved.', 'lesson_plan' => $plan, 'record' => $record, 'ai_credits' => $this->creditPayload($usage, $creditCost)]);
    }

    public function generateNote(Request $request, AiLessonPlanGeneratorService $generator, SubscriptionAiCreditService $credits): JsonResponse
    {
        $auth = $request->user();
        $this->authorizeTeacherOrAdmin($auth);

        $data = $request->validate($this->baseAcademicRules() + [
            'scheme_id' => 'nullable|exists:lesson_schemes,id',
            'lesson_plan_id' => 'nullable|exists:generated_lesson_plans,id',
            'subject' => 'required|string|max:150',
            'class' => 'required|string|max:150',
            'topic' => 'required|string|max:180',
            'depth' => 'nullable|in:short,standard,detailed',
            'teacher_notes' => 'nullable|string|max:5000',
            'youtube_videos' => 'nullable|array',
            'youtube_videos.*' => 'nullable|url|max:500',
        ]);

        $schoolId = (int) $auth->school_id;
        $scope = $this->resolveAcademicScope($data, $schoolId, $auth);
        $featureKey = 'ai_lesson_note_generator';
        $creditCost = $credits->costForFeature($featureKey);
        $credits->assertCreditsAvailable($schoolId, $featureKey, $creditCost);

        $scheme = ! empty($data['scheme_id']) ? LessonScheme::query()->where('school_id', $schoolId)->find($data['scheme_id']) : null;
        $plan = ! empty($data['lesson_plan_id']) ? GeneratedLessonPlan::query()->where('school_id', $schoolId)->find($data['lesson_plan_id']) : null;

        try {
            $result = $generator->generateLessonNote($data + [
                'scheme_context' => $scheme?->only(['title', 'topics', 'content']),
                'lesson_plan' => $plan?->plan,
            ]);
        } catch (Throwable $exception) {
            $this->logAiUsage($request, $featureKey, 'failed', [], ['error' => $exception->getMessage(), 'input' => $data]);
            return response()->json(['message' => 'Something went wrong while processing this AI request. Please try again later.'], 422);
        }

        $usage = $credits->consumeCredits($schoolId, $featureKey, $creditCost, $this->reference('ai-lesson-note', $schoolId), $data);
        $noteData = $result['lesson_note'];
        $note = LessonNote::query()->create($scope + [
            'school_id' => $schoolId,
            'scheme_id' => $data['scheme_id'] ?? null,
            'lesson_plan_id' => $data['lesson_plan_id'] ?? null,
            'created_by' => $auth->id,
            'subject' => $noteData['subject'] ?: $data['subject'],
            'class_name' => $noteData['class'] ?: $data['class'],
            'topic' => $noteData['topic'] ?: $data['topic'],
            'title' => $noteData['title'],
            'content' => $noteData,
            'youtube_videos' => $this->verifiedYoutubeLinks($data['youtube_videos'] ?? []),
            'status' => 'draft',
        ]);

        $this->logAiUsage($request, $featureKey, 'success', $result['usage'] ?? [], ['lesson_note_id' => $note->id], $usage->id, $creditCost);

        return response()->json(['message' => 'Lesson note generated and saved.', 'lesson_note' => $note, 'ai_credits' => $this->creditPayload($usage, $creditCost)]);
    }

    public function updateScheme(Request $request, LessonScheme $scheme): JsonResponse
    {
        $this->authorizeLessonEditAccess($request, $scheme);

        $data = $request->validate($this->baseAcademicRules() + [
            'title' => 'required|string|max:180',
            'subject' => 'required|string|max:150',
            'class' => 'required|string|max:150',
            'term' => 'nullable|string|max:80',
            'curriculum' => 'nullable|string|max:120',
            'content' => 'nullable|string|max:50000',
            'topics' => 'nullable|array',
            'status' => 'nullable|in:active,draft,archived',
        ]);

        $scope = $this->resolveAcademicScope($data, (int) $scheme->school_id, $request->user());

        $scheme->update($scope + [
            'title' => $data['title'],
            'subject' => $data['subject'],
            'class_name' => $data['class'],
            'term' => $data['term'] ?? null,
            'curriculum' => $data['curriculum'] ?? null,
            'content' => $data['content'] ?? null,
            'topics' => $data['topics'] ?? $this->topicsFromText((string) ($data['content'] ?? '')),
            'status' => $data['status'] ?? $scheme->status,
        ]);

        return response()->json(['message' => 'Scheme of work updated.', 'scheme' => $scheme->fresh()]);
    }

    public function updatePlan(Request $request, GeneratedLessonPlan $plan): JsonResponse
    {
        $this->authorizeLessonEditAccess($request, $plan);

        $data = $request->validate($this->baseAcademicRules() + [
            'scheme_id' => 'nullable|exists:lesson_schemes,id',
            'subject' => 'required|string|max:150',
            'class' => 'required|string|max:150',
            'topic' => 'required|string|max:180',
            'duration_minutes' => 'required|integer|min:10|max:240',
            'plan' => 'required|array',
            'status' => 'nullable|in:draft,published,archived',
        ]);

        $scope = $this->resolveAcademicScope($data, (int) $plan->school_id, $request->user());

        $plan->update($scope + [
            'scheme_id' => $data['scheme_id'] ?? null,
            'subject' => $data['subject'],
            'class_name' => $data['class'],
            'topic' => $data['topic'],
            'duration_minutes' => (int) $data['duration_minutes'],
            'plan' => $data['plan'],
            'status' => $data['status'] ?? $plan->status,
        ]);

        return response()->json(['message' => 'Lesson plan updated.', 'lesson_plan' => $plan->fresh()]);
    }

    public function updateNote(Request $request, LessonNote $note): JsonResponse
    {
        $this->authorizeLessonEditAccess($request, $note);
        $data = $request->validate($this->baseAcademicRules() + [
            'scheme_id' => 'nullable|exists:lesson_schemes,id',
            'lesson_plan_id' => 'nullable|exists:generated_lesson_plans,id',
            'title' => 'nullable|string|max:180',
            'subject' => 'nullable|string|max:150',
            'class' => 'nullable|string|max:150',
            'topic' => 'nullable|string|max:180',
            'content' => 'nullable|array',
            'youtube_videos' => 'nullable|array',
            'youtube_videos.*' => 'nullable|url|max:500',
            'status' => 'nullable|in:draft,published',
        ]);

        $scope = $this->resolveAcademicScope($data, (int) $note->school_id, $request->user());

        if (array_key_exists('youtube_videos', $data)) {
            $data['youtube_videos'] = $this->verifiedYoutubeLinks($data['youtube_videos'] ?? []);
        }

        if (($data['status'] ?? null) === 'published') {
            $data['published_at'] = now();
            $data['published_by'] = $request->user()->id;
        }

        if (array_key_exists('class', $data)) {
            $data['class_name'] = $data['class'];
            unset($data['class']);
        }

        $note->update(array_merge($scope, $data));

        return response()->json(['message' => 'Lesson note updated.', 'lesson_note' => $note->fresh()]);
    }
    public function publishNote(Request $request, LessonNote $note): JsonResponse
    {
        $this->authorizeSchoolResource($request, $note->school_id);
        $data = $request->validate([
            'youtube_videos' => 'nullable|array',
            'youtube_videos.*' => 'nullable|url|max:500',
        ]);

        $note->update([
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $request->user()->id,
            'youtube_videos' => $this->verifiedYoutubeLinks($data['youtube_videos'] ?? $note->youtube_videos ?? []),
        ]);

        return response()->json(['message' => 'Lesson note published to students.', 'lesson_note' => $note->fresh()]);
    }

    public function archiveScheme(Request $request, LessonScheme $scheme): JsonResponse
    {
        $this->authorizeLessonEditAccess($request, $scheme);

        $scheme->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        return response()->json(['message' => 'Scheme of work archived.']);
    }

    public function archivePlan(Request $request, GeneratedLessonPlan $plan): JsonResponse
    {
        $this->authorizeLessonEditAccess($request, $plan);

        $plan->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        return response()->json(['message' => 'Lesson plan archived.']);
    }

    public function archiveNote(Request $request, LessonNote $note): JsonResponse
    {
        $this->authorizeLessonEditAccess($request, $note);

        $note->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        return response()->json(['message' => 'Lesson note archived.']);
    }
    public function studentNotes(Request $request): JsonResponse
    {
        $auth = $request->user();
        abort_unless(strtolower((string) $auth->role) === 'student', 403);

        $period = $this->currentPeriodPayload((int) $auth->school_id);

        $className = optional($auth->level)->name;
        $notes = LessonNote::query()
            ->where('school_id', (int) $auth->school_id)
            ->where('status', 'published')
            ->whereNull('archived_at')
            ->when(! empty($period['academic_session_id']), fn ($query) => $query->where('academic_session_id', (int) $period['academic_session_id']))
            ->when(! empty($period['term_id']), fn ($query) => $query->where('term_id', (int) $period['term_id']))
            ->where(function ($query) use ($auth, $className) {
                $query->where('level_id', (int) $auth->level_id)
                    ->orWhere(function ($fallback) use ($className) {
                        $fallback->whereNull('level_id')->where('class_name', $className);
                    });
            })
            ->where(function ($query) use ($auth) {
                $query->whereNull('section_id')->orWhere('section_id', (int) $auth->section_id);
            })
            ->where(function ($query) use ($auth) {
                $query->whereNull('department_id')->orWhere('department_id', (int) $auth->department_id);
            })
            ->latest('published_at')
            ->paginate(20);

        return response()->json(['lesson_notes' => $notes, 'current_period' => $period]);
    }

    private function baseAcademicRules(): array
    {
        return [
            'level_id' => 'nullable|integer',
            'section_id' => 'nullable|integer',
            'department_id' => 'nullable|integer',
            'subject_id' => 'nullable|integer',
            'academic_session_id' => 'nullable|integer',
            'term_id' => 'nullable|integer',
        ];
    }

    private function resolveAcademicScope(array $data, int $schoolId, $auth = null): array
    {
        $levelId = ! empty($data['level_id']) ? (int) $data['level_id'] : null;
        $sectionId = ! empty($data['section_id']) ? (int) $data['section_id'] : null;
        $departmentId = ! empty($data['department_id']) ? (int) $data['department_id'] : null;
        $subjectId = ! empty($data['subject_id']) ? (int) $data['subject_id'] : null;
        $academicSessionId = ! empty($data['academic_session_id']) ? (int) $data['academic_session_id'] : null;
        $termId = ! empty($data['term_id']) ? (int) $data['term_id'] : null;

        if ($levelId) {
            abort_unless(StudentClass::query()->where('school_id', $schoolId)->whereKey($levelId)->exists(), 422, 'Selected class is invalid for this school.');
        }
        if ($sectionId) {
            abort_unless(Section::query()->where('school_id', $schoolId)->whereKey($sectionId)->exists(), 422, 'Selected section is invalid for this school.');
        }
        if ($departmentId) {
            abort_unless(Department::query()->where('school_id', $schoolId)->whereKey($departmentId)->exists(), 422, 'Selected department is invalid for this school.');
        }
        if ($subjectId) {
            abort_unless(Subject::query()->where('school_id', $schoolId)->whereKey($subjectId)->exists(), 422, 'Selected subject is invalid for this school.');
        }
        if ($academicSessionId && Schema::hasTable('academic_sessions')) {
            abort_unless(DB::table('academic_sessions')->where('school_id', $schoolId)->where('id', $academicSessionId)->exists(), 422, 'Selected academic session is invalid for this school.');
        }
        if ($termId && Schema::hasTable('terms')) {
            abort_unless(DB::table('terms')->where('school_id', $schoolId)->where('id', $termId)->exists(), 422, 'Selected term is invalid for this school.');
        }

        $period = $this->currentPeriodPayload($schoolId);
        $academicSessionId = $academicSessionId ?: ($period['academic_session_id'] ?? null);
        $termId = $termId ?: ($period['term_id'] ?? null);

        $scope = [
            'level_id' => $levelId,
            'section_id' => $sectionId,
            'department_id' => $departmentId,
            'subject_id' => $subjectId,
            'academic_session_id' => $academicSessionId,
            'term_id' => $termId,
        ];

        return $this->applyTeacherAcademicScope($scope, $schoolId, $auth);
    }

    private function applyTeacherAcademicScope(array $scope, int $schoolId, $auth): array
    {
        if (! $this->isTeacher($auth)) {
            return $scope;
        }

        $allowedLevelIds = $this->teacherLevelIds($auth, $schoolId);
        abort_if(empty($allowedLevelIds), 422, 'No class has been assigned to this teacher. Ask the admin to assign a class first.');

        if (! empty($scope['level_id'])) {
            abort_unless(in_array((int) $scope['level_id'], $allowedLevelIds, true), 403, 'This class is not assigned to this teacher.');
        } elseif (count($allowedLevelIds) === 1) {
            $scope['level_id'] = $allowedLevelIds[0];
        } else {
            abort(422, 'Select one of your assigned classes.');
        }

        $allowedSubjectIds = $this->teacherSubjectIds($auth, $schoolId);
        abort_if(empty($allowedSubjectIds), 422, 'No subject has been assigned to this teacher. Ask the admin to assign subjects first.');
        abort_unless(! empty($scope['subject_id']), 422, 'Select one of your assigned subjects.');
        abort_unless(in_array((int) $scope['subject_id'], $allowedSubjectIds, true), 403, 'This subject is not assigned to this teacher.');

        if (! empty($scope['subject_id'])) {
            $subject = Subject::query()
                ->where('school_id', $schoolId)
                ->whereKey((int) $scope['subject_id'])
                ->first(['id', 'section_id', 'department_id']);

            abort_unless($subject, 422, 'Selected subject is invalid for this school.');

            if (! empty($subject->section_id)) {
                if (! empty($scope['section_id'])) {
                    abort_unless((int) $scope['section_id'] === (int) $subject->section_id, 422, 'Selected section does not match the selected subject.');
                }
                $scope['section_id'] = (int) $subject->section_id;
            }

            if (! empty($subject->department_id)) {
                if (! empty($scope['department_id'])) {
                    abort_unless((int) $scope['department_id'] === (int) $subject->department_id, 422, 'Selected department does not match the selected subject.');
                }
                $scope['department_id'] = (int) $subject->department_id;
            }
        }

        if (empty($scope['section_id']) && ! empty($scope['level_id'])) {
            $classSectionId = StudentClass::query()
                ->where('school_id', $schoolId)
                ->whereKey((int) $scope['level_id'])
                ->value('section_id');
            if ($classSectionId) {
                $scope['section_id'] = (int) $classSectionId;
            }
        }

        return $scope;
    }

    private function academicScopePayload($auth, int $schoolId): array
    {
        $classes = StudentClass::query()
            ->where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'section_id']);

        $subjects = Subject::query()
            ->where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'section_id', 'department_id', 'class_id']);

        if ($this->isTeacher($auth)) {
            $levelIds = $this->teacherLevelIds($auth, $schoolId);
            $subjectIds = $this->teacherSubjectIds($auth, $schoolId);
            $classes = $classes->whereIn('id', $levelIds)->values();
            $subjects = $subjects->whereIn('id', $subjectIds)->values();
        }

        return [
            'is_teacher' => $this->isTeacher($auth),
            'classes' => $classes->values(),
            'subjects' => $subjects->values(),
            'locked_level_id' => $this->isTeacher($auth) && $classes->count() === 1 ? (int) $classes->first()->id : null,
        ];
    }

    private function currentPeriodPayload(int $schoolId): array
    {
        $session = Schema::hasTable('academic_sessions')
            ? DB::table('academic_sessions')
                ->where('school_id', $schoolId)
                ->where('is_current', 1)
                ->orderByDesc('id')
                ->first(['id', 'name'])
            : null;

        $term = Schema::hasTable('terms')
            ? DB::table('terms')
                ->where('school_id', $schoolId)
                ->where('status', 'Active')
                ->orderByRaw('COALESCE(sort_order, 999999) ASC')
                ->orderBy('id')
                ->first(['id', 'name'])
            : null;

        return [
            'academic_session_id' => $session?->id ? (int) $session->id : null,
            'academic_session_name' => $session?->name,
            'term_id' => $term?->id ? (int) $term->id : null,
            'term_name' => $term?->name,
        ];
    }

    private function teacherLevelIds($auth, int $schoolId): array
    {
        if (! $auth || ! Schema::hasTable('teacher_enrollments')) {
            return [];
        }

        $query = DB::table('teacher_enrollments')->where('enroll', 1);
        if (Schema::hasColumn('teacher_enrollments', 'school_id')) {
            $query->where('school_id', $schoolId);
        }

        $query->where(function ($inner) use ($auth) {
            $inner->where('user_id', (int) $auth->id);
            if (Schema::hasColumn('teacher_enrollments', 'teacher_id')) {
                $inner->orWhere('teacher_id', (int) $auth->id);
            }
        });

        return $query->pluck('level_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function teacherSubjectIds($auth, int $schoolId): array
    {
        if (! $auth || ! Schema::hasTable('teacher_subjects')) {
            return [];
        }

        return DB::table('teacher_subjects as ts')
            ->join('subjects as s', 's.id', '=', 'ts.subject_id')
            ->where('ts.teacher_id', (int) $auth->id)
            ->where('s.school_id', $schoolId)
            ->whereNull('s.archived_at')
            ->pluck('ts.subject_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function isTeacher($auth): bool
    {
        return strtolower((string) ($auth->role ?? '')) === 'teacher';
    }

    private function verifiedYoutubeLinks(array $links): array
    {
        return collect($links)
            ->map(fn ($link) => trim((string) $link))
            ->filter(fn ($link) => $link !== '' && filter_var($link, FILTER_VALIDATE_URL))
            ->filter(function ($link) {
                $host = strtolower((string) parse_url($link, PHP_URL_HOST));
                return str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be');
            })
            ->values()
            ->all();
    }

    private function authorizeTeacherOrAdmin($auth): void
    {
        abort_unless($auth && in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'teacher', 'principal'], true), 403, 'Unauthorized.');
    }

    private function authorizeSchoolResource(Request $request, int $schoolId): void
    {
        $auth = $request->user();
        $this->authorizeTeacherOrAdmin($auth);
        abort_unless((int) $auth->school_id === (int) $schoolId, 403, 'Unauthorized.');
    }

    private function authorizeLessonEditAccess(Request $request, $resource): void
    {
        $this->authorizeSchoolResource($request, (int) $resource->school_id);

        if ($this->isTeacher($request->user())) {
            abort_unless((int) $resource->created_by === (int) $request->user()->id, 403, 'Teachers can only edit lesson content they created.');
        }
    }

    private function topicsFromText(string $content): array
    {
        return collect(preg_split('/\r\n|\r|\n/', $content))
            ->map(fn ($line) => trim(preg_replace('/^[-*\d\.)\s]+/', '', (string) $line)))
            ->filter()
            ->take(20)
            ->map(fn ($topic, $index) => ['week' => $index + 1, 'topic' => $topic])
            ->values()
            ->all();
    }

    private function reference(string $prefix, int $schoolId): string
    {
        return $prefix . ':' . $schoolId . ':' . now()->format('YmdHis') . ':' . bin2hex(random_bytes(4));
    }

    private function creditPayload($usage, int $creditCost): array
    {
        return ['charged' => $creditCost, 'remaining' => $usage->remainingCredits(), 'allocated' => (int) $usage->allocated_credits, 'used' => (int) $usage->used_credits];
    }

    private function logAiUsage(Request $request, string $featureKey, string $status, array $usage = [], array $metadata = [], ?int $creditUsageId = null, int $creditsCharged = 0): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ai_usage_logs')) {
            return;
        }

        DB::table('ai_usage_logs')->insert([
            'school_id' => $request->user()?->school_id,
            'user_id' => $request->user()?->id,
            'subscription_ai_usage_id' => $creditUsageId,
            'feature_key' => $featureKey,
            'provider' => 'openai',
            'model' => $usage['model'] ?? config('openai.model'),
            'status' => $status,
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'items_generated' => $status === 'success' ? 1 : 0,
            'credits_charged' => $creditsCharged,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}



