<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CbtExam;
use App\Models\CbtExamSection;
use App\Models\CbtQuestion;
use App\Models\CbtQuestionGroup;
use App\Services\CbtAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CbtExamController extends Controller
{
    public function __construct(private readonly CbtAccessService $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->access->ensureCanUse($request->user(), 'online');

        $query = CbtExam::query()
            ->with(['subject:id,name', 'class:id,name', 'term:id,name', 'academicSession:id,name'])
            ->withCount(['sections', 'questionGroups', 'questions', 'attempts'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('delivery_mode')) {
            $query->where('delivery_mode', $request->query('delivery_mode'));
        }

        return response()->json([
            'exams' => $query->paginate((int) $request->query('per_page', 20)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->normalizeExamData($this->validateExam($request));
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($data['delivery_mode']) ? 'offline' : 'online');

        $exam = CbtExam::create($data + [
            'created_by' => $request->user()->id,
            'school_id' => $request->user()->school_id,
            'exam_code' => $data['exam_code'] ?? 'CBT-' . now()->format('ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6)),
        ]);

        return response()->json([
            'message' => 'CBT exam created.',
            'exam' => $exam->fresh(['subject', 'class', 'term', 'academicSession']),
        ], 201);
    }

    public function show(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($exam->delivery_mode) ? 'offline' : 'online');

        return response()->json([
            'exam' => $exam->load([
                'subject:id,name',
                'class:id,name',
                'section:id,name',
                'department:id,name',
                'term:id,name',
                'academicSession:id,name',
                'sections.questionGroups.questions.options',
                'sections.questions.options',
                'questionGroups.questions.options',
                'questions.options',
            ]),
        ]);
    }

    public function update(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        abort_if($exam->status === 'published', 422, 'Published CBT exams must be reopened before editing.');

        $data = $this->normalizeExamData($this->validateExam($request));
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($data['delivery_mode']) ? 'offline' : 'online');

        $exam->update($data);

        return response()->json([
            'message' => 'CBT exam updated.',
            'exam' => $exam->fresh(['subject', 'class', 'term', 'academicSession']),
        ]);
    }

    public function destroy(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        abort_if($exam->attempts()->exists(), 422, 'This CBT exam already has student attempts. Archive it instead.');

        $exam->delete();

        return response()->json(['message' => 'CBT exam deleted.']);
    }

    public function publish(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($exam->delivery_mode) ? 'offline' : 'online');

        abort_if($exam->questions()->count() === 0, 422, 'Add at least one question before publishing this CBT exam.');

        $exam->update([
            'status' => 'published',
            'published_at' => now(),
            'total_marks' => (float) $exam->questions()->sum('marks'),
        ]);

        return response()->json([
            'message' => 'CBT exam published.',
            'exam' => $exam->fresh(),
        ]);
    }

    public function close(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        $exam->update(['status' => 'closed']);

        return response()->json([
            'message' => 'CBT exam closed.',
            'exam' => $exam->fresh(),
        ]);
    }

    public function storeSection(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        abort_if($exam->status === 'published', 422, 'Published CBT exams must be reopened before editing.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
            'default_marks' => ['nullable', 'numeric', 'min:0'],
            'shuffle_questions' => ['nullable', 'boolean'],
        ]);
        $data = array_filter($data, fn ($value) => $value !== null);

        $section = $exam->sections()->create($data);

        return response()->json([
            'message' => 'CBT section added.',
            'section' => $section,
        ], 201);
    }

    public function storeGroup(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        abort_if($exam->status === 'published', 422, 'Published CBT exams must be reopened before editing.');

        $data = $request->validate([
            'section_id' => ['nullable', 'exists:cbt_exam_sections,id'],
            'group_type' => ['required', Rule::in(['instruction', 'comprehension', 'case_study'])],
            'title' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'passage' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
        ]);
        $data = array_filter($data, fn ($value) => $value !== null);

        if (! empty($data['section_id'])) {
            abort_unless(CbtExamSection::where('exam_id', $exam->id)->where('id', $data['section_id'])->exists(), 422, 'Selected section does not belong to this exam.');
        }

        $group = $exam->questionGroups()->create($data);

        return response()->json([
            'message' => 'Question instruction group added.',
            'group' => $group,
        ], 201);
    }

    public function storeQuestion(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        abort_if($exam->status === 'published', 422, 'Published CBT exams must be reopened before editing.');

        $data = $this->validateQuestion($request);

        if (! empty($data['section_id'])) {
            abort_unless(CbtExamSection::where('exam_id', $exam->id)->where('id', $data['section_id'])->exists(), 422, 'Selected section does not belong to this exam.');
        }

        if (! empty($data['question_group_id'])) {
            abort_unless(CbtQuestionGroup::where('exam_id', $exam->id)->where('id', $data['question_group_id'])->exists(), 422, 'Selected question group does not belong to this exam.');
        }

        $options = $data['options'] ?? [];
        unset($data['options']);

        $question = DB::transaction(function () use ($exam, $data, $options) {
            $question = $exam->questions()->create($data);
            $this->syncOptions($question, $options);
            $exam->update(['total_marks' => (float) $exam->questions()->sum('marks')]);

            return $question->fresh('options');
        });

        return response()->json([
            'message' => 'CBT question added.',
            'question' => $question,
        ], 201);
    }

    public function updateQuestion(Request $request, CbtQuestion $question): JsonResponse
    {
        $exam = $question->exam;
        $this->ensureSameSchool($request, $exam);
        abort_if($exam->status === 'published', 422, 'Published CBT exams must be reopened before editing.');

        $data = $this->validateQuestion($request);
        $options = $data['options'] ?? [];
        unset($data['options']);

        DB::transaction(function () use ($exam, $question, $data, $options) {
            $question->update($data);
            $this->syncOptions($question, $options);
            $exam->update(['total_marks' => (float) $exam->questions()->sum('marks')]);
        });

        return response()->json([
            'message' => 'CBT question updated.',
            'question' => $question->fresh('options'),
        ]);
    }

    public function deleteQuestion(Request $request, CbtQuestion $question): JsonResponse
    {
        $exam = $question->exam;
        $this->ensureSameSchool($request, $exam);
        abort_if($exam->status === 'published', 422, 'Published CBT exams must be reopened before editing.');

        $question->delete();
        $exam->update(['total_marks' => (float) $exam->questions()->sum('marks')]);

        return response()->json(['message' => 'CBT question deleted.']);
    }

    private function validateExam(Request $request): array
    {
        return $request->validate([
            'subject_id' => ['nullable', 'exists:subjects,id'],
            'class_id' => ['nullable', 'exists:student_classes,id'],
            'section_id' => ['nullable', 'exists:sections,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'term_id' => ['nullable', 'exists:terms,id'],
            'academic_session_id' => ['nullable', 'exists:academic_sessions,id'],
            'title' => ['required', 'string', 'max:255'],
            'exam_code' => ['nullable', 'string', 'max:100'],
            'delivery_mode' => ['required', Rule::in(['online', 'offline', 'hybrid'])],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'pass_mark' => ['nullable', 'numeric', 'min:0'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_options' => ['nullable', 'boolean'],
            'show_result_after_submit' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'general_instructions' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
        ]);
    }

    private function validateQuestion(Request $request): array
    {
        return $request->validate([
            'section_id' => ['nullable', 'exists:cbt_exam_sections,id'],
            'question_group_id' => ['nullable', 'exists:cbt_question_groups,id'],
            'question_type' => ['required', Rule::in(['single_choice', 'multiple_choice', 'true_false', 'fill_blank', 'theory'])],
            'question_text' => ['required', 'string'],
            'instructions' => ['nullable', 'string'],
            'explanation' => ['nullable', 'string'],
            'marks' => ['required', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
            'difficulty' => ['nullable', 'string', 'max:50'],
            'correct_answer' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'options' => ['nullable', 'array'],
            'options.*.label' => ['nullable', 'string', 'max:10'],
            'options.*.option_text' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.sort_order' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function normalizeExamData(array $data): array
    {
        foreach (['pass_mark', 'max_attempts', 'duration_minutes'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }

        foreach (['shuffle_questions', 'shuffle_options', 'show_result_after_submit'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function syncOptions(CbtQuestion $question, array $options): void
    {
        $question->options()->delete();

        foreach ($options as $index => $option) {
            $question->options()->create([
                'label' => $option['label'] ?? chr(65 + $index),
                'option_text' => $option['option_text'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'sort_order' => (int) ($option['sort_order'] ?? ($index + 1)),
            ]);
        }
    }

    private function requiresOffline(string $mode): bool
    {
        return in_array($mode, ['offline', 'hybrid'], true);
    }

    private function ensureSameSchool(Request $request, CbtExam $exam): void
    {
        abort_unless((int) $exam->school_id === (int) $request->user()->school_id, 403);
    }
}
