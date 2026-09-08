<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtAttemptEvent;
use App\Models\CbtExam;
use App\Models\CbtQuestion;
use App\Services\CbtAccessService;
use App\Services\SchoolFeeAccessPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CbtStudentExamController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly SchoolFeeAccessPolicyService $feeAccessPolicy,
    )
    {
    }

    public function available(Request $request): JsonResponse
    {
        $this->access->ensureCanUse($request->user(), 'online');

        $student = $request->user();

        $exams = CbtExam::query()
            ->with(['subject:id,name', 'class:id,name', 'term:id,name', 'academicSession:id,name'])
            ->withCount('questions')
            ->where('school_id', $student->school_id)
            ->where('status', 'published')
            ->whereIn('delivery_mode', ['online', 'hybrid'])
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->where(function ($query) use ($student) {
                $query->whereNull('class_id')->orWhere('class_id', $student->level_id);
            })
            ->where(function ($query) use ($student) {
                $query->whereNull('section_id')->orWhere('section_id', $student->section_id);
            })
            ->where(function ($query) use ($student) {
                $query->whereNull('department_id')->orWhere('department_id', $student->department_id);
            })
            ->latest('published_at')
            ->get();

        return response()->json([
            'exams' => $exams->map(function (CbtExam $exam) use ($student) {
                $feeAccess = $this->feeAccessPolicy->cbtAccessStatus(
                    (int) $exam->school_id,
                    (int) $student->id,
                    $exam->academic_session_id ? (int) $exam->academic_session_id : null,
                    $exam->term_id ? (int) $exam->term_id : null,
                );

                return $exam->toArray() + [
                    'fee_access' => [
                        'allowed' => (bool) $feeAccess['allowed'],
                        'message' => $feeAccess['message'],
                        'required_percent' => $feeAccess['required_percent'],
                        'summary' => $feeAccess['summary'],
                    ],
                ];
            })->values(),
        ]);
    }

    public function start(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureStudentCanAccess($request, $exam);

        $student = $request->user();
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
            'status' => 'in_progress',
            'started_at' => now(),
            'expires_at' => now()->addMinutes((int) $exam->duration_minutes),
            'total_marks' => (float) $exam->questions()->sum('marks'),
            'device_name' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'CBT exam started.',
            'attempt' => $attempt,
            'exam' => $this->studentExamPayload($exam),
            'student' => $this->studentPayload($student),
        ], 201);
    }

    public function saveAnswer(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->ensureOwnAttempt($request, $attempt);
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
            return response()->json([
                'message' => 'Existing answer kept.',
                'answer' => $existingAnswer,
            ]);
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

        return response()->json([
            'message' => 'Answer saved.',
            'answer' => $answer,
        ]);
    }

    public function submit(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->ensureOwnAttempt($request, $attempt);
        abort_unless($attempt->status === 'in_progress', 422, 'This CBT attempt has already been submitted.');

        $answersBundle = $request->input('answers_bundle');

        $attempt = DB::transaction(function () use ($attempt, $answersBundle) {
            if (is_array($answersBundle) && ! empty($answersBundle)) {
                $examQuestions = CbtQuestion::with('options')
                    ->where('exam_id', $attempt->exam_id)
                    ->get()
                    ->keyBy('id');

                foreach ($answersBundle as $item) {
                    $questionId = (int) ($item['question_id'] ?? 0);
                    $question = $examQuestions->get($questionId);
                    if (! $question) {
                        continue;
                    }

                    $selectedOptionIds = is_array($item['selected_option_ids'] ?? null) ? $item['selected_option_ids'] : [];
                    $answerText = $item['answer_text'] ?? null;
                    $hasAnswerContent = count($selectedOptionIds) > 0 || trim((string) $answerText) !== '';

                    if (! $hasAnswerContent) {
                        continue;
                    }

                    $score = $this->scoreQuestion($question, $selectedOptionIds, $answerText);

                    CbtAnswer::updateOrCreate(
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
                }
            }

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
            'attempt' => $attempt,
            'show_result' => (bool) $attempt->exam->show_result_after_submit,
        ]);
    }

    public function logEvent(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->ensureOwnAttempt($request, $attempt);
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

    private function ensureStudentCanAccess(Request $request, CbtExam $exam): void
    {
        $this->access->ensureCanUse($request->user(), 'online');
        $student = $request->user();

        abort_unless((int) $exam->school_id === (int) $student->school_id, 403);
        abort_unless($student->role === 'Student', 403, 'Only students can take CBT exams.');
        abort_unless($exam->status === 'published', 422, 'This CBT exam is not available.');
        abort_if($exam->starts_at && $exam->starts_at->isFuture(), 422, 'This CBT exam has not started.');
        abort_if($exam->ends_at && $exam->ends_at->isPast(), 422, 'This CBT exam has ended.');

        $feeBlock = $this->feeAccessPolicy->assertCbtAccess(
            (int) $exam->school_id,
            (int) $student->id,
            $exam->academic_session_id ? (int) $exam->academic_session_id : null,
            $exam->term_id ? (int) $exam->term_id : null,
        );

        abort_if($feeBlock, 403, $feeBlock['message'] ?? 'Access denied. Complete the required school fee payment before starting this exam.');

        if ($exam->class_id) {
            abort_unless((int) $exam->class_id === (int) $student->level_id, 403, 'This CBT exam is not assigned to your class.');
        }

        if ($exam->section_id) {
            abort_unless((int) $exam->section_id === (int) $student->section_id, 403, 'This CBT exam is not assigned to your section.');
        }

        if ($exam->department_id) {
            abort_unless((int) $exam->department_id === (int) $student->department_id, 403, 'This CBT exam is not assigned to your department.');
        }
    }

    private function ensureOwnAttempt(Request $request, CbtAttempt $attempt): void
    {
        abort_unless((int) $attempt->student_id === (int) $request->user()->id, 403);
        abort_unless((int) $attempt->school_id === (int) $request->user()->school_id, 403);
    }

    private function studentExamPayload(CbtExam $exam): array
    {
        $cachedStructure = Cache::remember("cbt_exam_blocks_{$exam->id}", now()->addHours(6), function () use ($exam) {
            $exam->load([
                'subject:id,name',
                'class:id,name',
                'term:id,name',
                'academicSession:id,name',
                'questionGroups.questions.options',
                'questions.options',
            ]);

            return [
                'id' => $exam->id,
                'title' => $exam->title,
                'exam_code' => $exam->exam_code,
                'duration_minutes' => (int) $exam->duration_minutes,
                'general_instructions' => $exam->general_instructions,
                'subject' => $exam->subject,
                'class' => $exam->class,
                'term' => $exam->term,
                'academic_session' => $exam->academicSession,
                'raw_blocks' => $this->rawQuestionBlocks($exam),
                'total_marks' => (float) $exam->questions()->sum('marks'),
            ];
        });

        $blocks = $this->buildStudentBlocks(
            $cachedStructure['raw_blocks'] ?? [],
            (bool) $exam->shuffle_questions,
            (bool) $exam->shuffle_options
        );

        return [
            'id' => $cachedStructure['id'],
            'title' => $cachedStructure['title'],
            'exam_code' => $cachedStructure['exam_code'],
            'duration_minutes' => $cachedStructure['duration_minutes'],
            'general_instructions' => $cachedStructure['general_instructions'],
            'subject' => $cachedStructure['subject'],
            'class' => $cachedStructure['class'],
            'term' => $cachedStructure['term'],
            'academic_session' => $cachedStructure['academic_session'],
            'question_blocks' => $blocks,
            'total_questions' => collect($blocks)->sum(fn ($block) => count($block['questions'] ?? [])),
            'total_marks' => $cachedStructure['total_marks'],
        ];
    }

    private function studentPayload($student): array
    {
        $student->loadMissing(['level:id,name', 'section:id,name', 'department:id,name']);

        return [
            'id' => $student->id,
            'name' => trim(($student->surname ?? '') . ' ' . ($student->firstname ?? '')),
            'reg_no' => $student->reg_no,
            'class' => $student->level?->name,
            'section' => $student->section?->name,
            'department' => $student->department?->name,
        ];
    }

    private function rawQuestionBlocks(CbtExam $exam): array
    {
        $groupedQuestionIds = $exam->questionGroups
            ->flatMap(fn ($group) => $group->questions->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->all();

        $blocks = collect();

        $exam->questions
            ->whereNotIn('id', $groupedQuestionIds)
            ->each(function (CbtQuestion $question) use ($blocks) {
                $blocks->push([
                    'type' => 'question',
                    'sort_order' => (int) $question->sort_order,
                    'questions' => [
                        [
                            'id' => $question->id,
                            'question_type' => $question->question_type,
                            'question_text' => $question->question_text,
                            'instructions' => $question->instructions,
                            'marks' => (float) $question->marks,
                            'raw_options' => $question->options->map(fn ($opt) => [
                                'id' => $opt->id,
                                'label' => $opt->label,
                                'option_text' => $opt->option_text,
                            ])->values()->all(),
                        ],
                    ],
                ]);
            });

        $exam->questionGroups->each(function ($group) use ($blocks) {
            $questions = $group->questions
                ->sortBy('sort_order')
                ->map(fn (CbtQuestion $question) => [
                    'id' => $question->id,
                    'question_type' => $question->question_type,
                    'question_text' => $question->question_text,
                    'instructions' => $question->instructions,
                    'marks' => (float) $question->marks,
                    'raw_options' => $question->options->map(fn ($opt) => [
                        'id' => $opt->id,
                        'label' => $opt->label,
                        'option_text' => $opt->option_text,
                    ])->values()->all(),
                ])
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

        return $blocks->sortBy('sort_order')->values()->all();
    }

    private function buildStudentBlocks(array $rawBlocks, bool $shuffleQuestions, bool $shuffleOptions): array
    {
        $blocks = collect($rawBlocks)->map(function ($block) use ($shuffleOptions) {
            $questions = collect($block['questions'] ?? [])->map(function ($q) use ($shuffleOptions) {
                $options = collect($q['raw_options'] ?? []);
                if ($shuffleOptions) {
                    $options = $options->shuffle();
                }

                $mappedOptions = $options->values()->map(fn ($opt, int $idx) => [
                    'id' => $opt['id'],
                    'label' => chr(65 + $idx),
                    'option_text' => $opt['option_text'],
                ])->all();

                return [
                    'id' => $q['id'],
                    'question_type' => $q['question_type'],
                    'question_text' => $q['question_text'],
                    'instructions' => $q['instructions'],
                    'marks' => (float) $q['marks'],
                    'options' => $mappedOptions,
                ];
            })->all();

            $block['questions'] = $questions;
            unset($block['raw_blocks']);
            return $block;
        });

        if ($shuffleQuestions) {
            $blocks = $blocks->shuffle();
        }

        return $blocks->values()->all();
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
