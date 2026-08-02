<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtAttemptEvent;
use App\Models\CbtExam;
use App\Models\CbtQuestion;
use App\Models\User;
use App\Services\CbtAccessService;
use App\Services\SchoolFeeAccessPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicCbtExamController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly SchoolFeeAccessPolicyService $feeAccessPolicy,
    )
    {
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_code' => ['required', 'string', 'max:80'],
            'student_reg_no' => ['required', 'string', 'max:80'],
        ]);

        [$admin, $student] = $this->resolveStudentContext($data['school_code'], $data['student_reg_no']);
        $this->access->ensureCanUse($admin, 'online');

        $exams = $this->availableExamQuery($student)
            ->with(['subject:id,name', 'class:id,name', 'term:id,name', 'academicSession:id,name', 'schedules'])
            ->withCount('questions')
            ->get();

        return response()->json([
            'school' => $this->schoolPayload($admin),
            'student' => $this->studentPayload($student),
            'exams' => $exams->map(fn (CbtExam $exam) => $this->examSummaryPayload($exam, $student))->values(),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_code' => ['required', 'string', 'max:80'],
            'student_reg_no' => ['required', 'string', 'max:80'],
            'exam_id' => ['required', 'integer', 'exists:cbt_exams,id'],
            'access_code' => ['nullable', 'string', 'max:80'],
        ]);

        [$admin, $student] = $this->resolveStudentContext($data['school_code'], $data['student_reg_no']);
        $this->access->ensureCanUse($admin, 'online');

        $exam = $this->availableExamQuery($student)
            ->where('id', (int) $data['exam_id'])
            ->firstOrFail();

        $feeBlock = $this->feeAccessPolicy->assertCbtAccess(
            (int) $exam->school_id,
            (int) $student->id,
            $exam->academic_session_id ? (int) $exam->academic_session_id : null,
            $exam->term_id ? (int) $exam->term_id : null,
        );

        abort_if($feeBlock, 403, $feeBlock['message'] ?? 'Access denied. Complete the required school fee payment before starting this exam.');

        if ($exam->access_code_required) {
            abort_unless(
                hash_equals((string) $exam->access_code, (string) ($data['access_code'] ?? '')),
                422,
                'Enter the correct CBT access code to start this exam.'
            );
        }

        $attemptCount = CbtAttempt::where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->where('status', '!=', 'cancelled')
            ->count();
        abort_if($attemptCount >= (int) $exam->max_attempts, 422, 'You have already used the allowed attempt for this CBT exam.');
        $attemptNumber = ((int) CbtAttempt::where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->max('attempt_number')) + 1;

        $attempt = CbtAttempt::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'school_id' => $exam->school_id,
            'delivery_mode' => 'online',
            'attempt_number' => $attemptNumber,
            'public_access_token' => Str::random(64),
            'status' => 'in_progress',
            'started_at' => now(),
            'expires_at' => now()->addMinutes((int) $exam->duration_minutes),
            'total_marks' => (float) $exam->questions()->sum('marks'),
            'device_name' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'CBT exam started.',
            'attempt' => $this->attemptPayload($attempt),
            'exam' => $this->studentExamPayload($exam),
        ], 201);
    }

    public function saveAnswer(Request $request, string $token): JsonResponse
    {
        $attempt = $this->attemptFromToken($token);
        abort_unless($attempt->status === 'in_progress', 422, 'This CBT attempt is no longer active.');
        abort_if($attempt->expires_at && $attempt->expires_at->isPast(), 422, 'This CBT attempt has expired. Please submit.');

        $data = $request->validate([
            'question_id' => ['required', 'exists:cbt_questions,id'],
            'selected_option_ids' => ['nullable', 'array'],
            'selected_option_ids.*' => ['integer', 'exists:cbt_question_options,id'],
            'answer_text' => ['nullable', 'string'],
        ]);

        $question = CbtQuestion::with('options')->where('exam_id', $attempt->exam_id)->findOrFail($data['question_id']);
        $selectedOptionIds = $data['selected_option_ids'] ?? [];
        $answerText = $data['answer_text'] ?? null;
        $hasAnswerContent = count($selectedOptionIds) > 0 || trim((string) $answerText) !== '';
        $existingAnswer = CbtAnswer::where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->first();

        if (! $hasAnswerContent && $existingAnswer) {
            return response()->json(['message' => 'Existing answer kept.', 'answer' => $existingAnswer]);
        }

        if (! $hasAnswerContent) {
            return response()->json(['message' => 'Blank answer ignored.']);
        }

        $score = $this->scoreQuestion($question, $selectedOptionIds, $answerText);

        $answer = CbtAnswer::updateOrCreate(
            [
                'attempt_id' => $attempt->id,
                'question_id' => $question->id,
            ],
            [
                'selected_option_ids' => $selectedOptionIds,
                'answer_text' => $answerText,
                'is_correct' => $score['is_correct'],
                'score' => $score['score'],
                'answered_at' => now(),
            ]
        );

        return response()->json(['message' => 'Answer saved.', 'answer' => $answer]);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $attempt = $this->attemptFromToken($token);
        abort_unless($attempt->status === 'in_progress', 422, 'This CBT attempt has already been submitted.');

        $attempt = DB::transaction(function () use ($attempt) {
            $attempt->update([
                'status' => $attempt->expires_at && $attempt->expires_at->isPast() ? 'auto_submitted' : 'submitted',
                'submitted_at' => now(),
                'score' => (float) $attempt->answers()->sum('score'),
                'total_marks' => (float) $attempt->exam->questions()->sum('marks'),
            ]);

            return $attempt->fresh(['exam', 'answers.question']);
        });

        return response()->json([
            'message' => 'CBT exam submitted.',
            'attempt' => $this->attemptPayload($attempt),
            'show_result' => (bool) $attempt->exam->show_result_after_submit,
        ]);
    }

    public function logEvent(Request $request, string $token): JsonResponse
    {
        $attempt = $this->attemptFromToken($token);
        abort_unless($attempt->status === 'in_progress', 422, 'This CBT attempt is no longer active.');

        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:60'],
            'severity' => ['nullable', 'string', 'in:low,medium,high'],
            'page_url' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        CbtAttemptEvent::create([
            'attempt_id' => $attempt->id,
            'exam_id' => $attempt->exam_id,
            'student_id' => $attempt->student_id,
            'school_id' => $attempt->school_id,
            'event_type' => $data['event_type'],
            'severity' => $data['severity'] ?? 'medium',
            'page_url' => $data['page_url'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $data['metadata'] ?? null,
            'occurred_at' => now(),
        ]);

        return response()->json(['message' => 'CBT security event recorded.']);
    }

    private function resolveStudentContext(string $schoolCode, string $studentRegNo): array
    {
        $admin = User::with('school')
            ->whereRaw('LOWER(role) = ?', ['admin'])
            ->where('reg_no', trim($schoolCode))
            ->whereNotNull('school_id')
            ->first();

        abort_unless($admin, 404, 'School code not found.');

        $student = User::with(['level:id,name', 'section:id,name', 'department:id,name'])
            ->where('school_id', $admin->school_id)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('reg_no', trim($studentRegNo))
            ->first();

        abort_unless($student, 404, 'Student admission number was not found for this school.');

        return [$admin, $student];
    }

    private function availableExamQuery(User $student)
    {
        $now = now();

        return CbtExam::query()
            ->where('school_id', $student->school_id)
            ->where('status', 'published')
            ->whereIn('delivery_mode', ['online', 'hybrid'])
            ->where(function ($query) use ($student) {
                $query->whereNull('class_id')->orWhere('class_id', $student->level_id);
            })
            ->where(function ($query) use ($student) {
                $query->whereNull('section_id')->orWhere('section_id', $student->section_id);
            })
            ->where(function ($query) use ($student) {
                $query->whereNull('department_id')->orWhere('department_id', $student->department_id);
            })
            ->where(function ($query) use ($now) {
                $query->whereHas('schedules', function ($schedule) use ($now) {
                    $schedule
                        ->whereDate('exam_date', $now->toDateString())
                        ->where('starts_at', '<=', $now->format('H:i:s'))
                        ->where('ends_at', '>=', $now->format('H:i:s'));
                })->orWhere(function ($fallback) use ($now) {
                    $fallback
                        ->whereDoesntHave('schedules')
                        ->where(function ($window) use ($now) {
                            $window->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                        })
                        ->where(function ($window) use ($now) {
                            $window->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                        });
                });
            })
            ->latest('published_at');
    }

    private function attemptFromToken(string $token): CbtAttempt
    {
        return CbtAttempt::with('exam')
            ->where('public_access_token', $token)
            ->firstOrFail();
    }

    private function examSummaryPayload(CbtExam $exam, ?User $student = null): array
    {
        $schedule = $exam->schedules->first();
        $feeAccess = $student
            ? $this->feeAccessPolicy->cbtAccessStatus(
                (int) $exam->school_id,
                (int) $student->id,
                $exam->academic_session_id ? (int) $exam->academic_session_id : null,
                $exam->term_id ? (int) $exam->term_id : null,
            )
            : null;

        return [
            'id' => $exam->id,
            'title' => $exam->title,
            'exam_code' => $exam->exam_code,
            'duration_minutes' => (int) $exam->duration_minutes,
            'access_code_required' => (bool) $exam->access_code_required,
            'calculator_enabled' => (bool) $exam->calculator_enabled,
            'questions_count' => (int) ($exam->questions_count ?? 0),
            'subject' => $exam->subject,
            'class' => $exam->class,
            'term' => $exam->term,
            'academic_session' => $exam->academicSession,
            'fee_access' => $feeAccess ? [
                'allowed' => (bool) $feeAccess['allowed'],
                'message' => $feeAccess['message'],
                'required_percent' => $feeAccess['required_percent'],
                'summary' => $feeAccess['summary'],
            ] : null,
            'schedule' => $schedule ? [
                'exam_date' => optional($schedule->exam_date)->toDateString(),
                'starts_at' => $schedule->starts_at,
                'ends_at' => $schedule->ends_at,
                'venue' => $schedule->venue,
            ] : null,
        ];
    }

    private function attemptPayload(CbtAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'token' => $attempt->public_access_token,
            'status' => $attempt->status,
            'started_at' => optional($attempt->started_at)->toIso8601String(),
            'expires_at' => optional($attempt->expires_at)->toIso8601String(),
        ];
    }

    private function schoolPayload(User $admin): array
    {
        return [
            'id' => $admin->school_id,
            'name' => $admin->school?->school_name ?? $admin->school?->name ?? 'School',
            'code' => $admin->reg_no,
        ];
    }

    private function studentPayload(User $student): array
    {
        return [
            'id' => $student->id,
            'name' => trim(($student->surname ?? '') . ' ' . ($student->firstname ?? '')),
            'reg_no' => $student->reg_no,
            'class' => $student->level?->name,
            'section' => $student->section?->name,
            'department' => $student->department?->name,
        ];
    }

    private function studentExamPayload(CbtExam $exam): array
    {
        $exam->load([
            'subject:id,name',
            'class:id,name',
            'term:id,name',
            'academicSession:id,name',
            'questionGroups.questions.options',
            'questions.options',
        ]);

        $blocks = $this->studentQuestionBlocks($exam);

        return [
            'id' => $exam->id,
            'title' => $exam->title,
            'exam_code' => $exam->exam_code,
            'duration_minutes' => (int) $exam->duration_minutes,
            'general_instructions' => $exam->general_instructions,
            'calculator_enabled' => (bool) $exam->calculator_enabled,
            'subject' => $exam->subject,
            'class' => $exam->class,
            'term' => $exam->term,
            'academic_session' => $exam->academicSession,
            'question_blocks' => $blocks,
            'total_questions' => collect($blocks)->sum(fn ($block) => count($block['questions'] ?? [])),
            'total_marks' => (float) $exam->questions()->sum('marks'),
        ];
    }

    private function studentQuestionBlocks(CbtExam $exam): array
    {
        $groupedQuestionIds = $exam->questionGroups
            ->flatMap(fn ($group) => $group->questions->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->all();

        $blocks = collect();

        $exam->questions
            ->whereNotIn('id', $groupedQuestionIds)
            ->each(function (CbtQuestion $question) use ($blocks, $exam) {
                $blocks->push([
                    'type' => 'question',
                    'sort_order' => (int) $question->sort_order,
                    'questions' => [$this->studentQuestionPayload($question, (bool) $exam->shuffle_options)],
                ]);
            });

        $exam->questionGroups->each(function ($group) use ($blocks, $exam) {
            $questions = $group->questions
                ->sortBy('sort_order')
                ->map(fn (CbtQuestion $question) => $this->studentQuestionPayload($question, (bool) $exam->shuffle_options))
                ->values()
                ->all();

            if ($questions === []) {
                return;
            }

            $blocks->push([
                'type' => 'group',
                'group_id' => $group->id,
                'group_type' => $group->group_type,
                'title' => $group->title,
                'instructions' => $group->instructions,
                'passage' => $group->passage,
                'sort_order' => (int) $group->sort_order,
                'questions' => $questions,
            ]);
        });

        $blocks = $blocks->sortBy('sort_order')->values();

        if ($exam->shuffle_questions) {
            $blocks = $blocks->shuffle()->values();
        }

        return $blocks->all();
    }

    private function studentQuestionPayload(CbtQuestion $question, bool $shuffleOptions): array
    {
        $options = $question->options
            ->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
                'option_text' => $option->option_text,
            ]);

        if ($shuffleOptions) {
            $options = $options->shuffle()->values();
        }

        $options = $options
            ->values()
            ->map(fn ($option, int $index) => [
                'id' => $option['id'],
                'label' => chr(65 + $index),
                'option_text' => $option['option_text'],
            ]);

        return [
            'id' => $question->id,
            'question_type' => $question->question_type,
            'question_text' => $question->question_text,
            'instructions' => $question->instructions,
            'marks' => (float) $question->marks,
            'options' => $options->all(),
        ];
    }

    private function scoreQuestion(CbtQuestion $question, array $selectedOptionIds, ?string $answerText): array
    {
        if (in_array($question->question_type, ['theory', 'fill_blank'], true)) {
            return ['is_correct' => null, 'score' => 0];
        }

        $correctIds = $question->options->where('is_correct', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $selectedIds = collect($selectedOptionIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        $isCorrect = $correctIds !== [] && $correctIds === $selectedIds;

        return [
            'is_correct' => $isCorrect,
            'score' => $isCorrect ? (float) $question->marks : 0,
        ];
    }
}
