<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtQuestion;
use App\Services\CbtAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CbtStudentExamController extends Controller
{
    public function __construct(private readonly CbtAccessService $access)
    {
    }

    public function available(Request $request): JsonResponse
    {
        $this->access->ensureCanUse($request->user(), 'online');

        $student = $request->user();

        $exams = CbtExam::query()
            ->with(['subject:id,name', 'term:id,name', 'academicSession:id,name'])
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

        return response()->json(['exams' => $exams]);
    }

    public function start(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureStudentCanAccess($request, $exam);

        $student = $request->user();
        $attemptCount = CbtAttempt::where('exam_id', $exam->id)->where('student_id', $student->id)->count();

        abort_if($attemptCount >= (int) $exam->max_attempts, 422, 'You have already used the allowed attempt for this CBT exam.');

        $attempt = CbtAttempt::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'school_id' => $exam->school_id,
            'delivery_mode' => 'online',
            'attempt_number' => $attemptCount + 1,
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
        $score = $this->scoreQuestion($question, $data['selected_option_ids'] ?? [], $data['answer_text'] ?? null);

        $answer = CbtAnswer::updateOrCreate(
            [
                'attempt_id' => $attempt->id,
                'question_id' => $question->id,
            ],
            [
                'selected_option_ids' => $data['selected_option_ids'] ?? null,
                'answer_text' => $data['answer_text'] ?? null,
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
            'attempt' => $attempt,
            'show_result' => (bool) $attempt->exam->show_result_after_submit,
        ]);
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
        $exam->load([
            'subject:id,name',
            'sections.questionGroups.questions.options',
            'sections.questions.options',
            'questionGroups.questions.options',
            'questions.options',
        ]);

        if ($exam->shuffle_options) {
            $exam->questions->each(fn ($question) => $question->setRelation('options', $question->options->shuffle()->values()));
        }

        return $exam->toArray();
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
