<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtExamSection;
use App\Models\CbtQuestion;
use App\Models\CbtQuestionGroup;
use App\Services\Cbt\CbtQuestionImportService;
use App\Services\CbtAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Exports\ResultTemplateExport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;

class CbtExamController extends Controller
{
    public function __construct(private readonly CbtAccessService $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->access->ensureCanUse($request->user(), 'online');

        $query = CbtExam::query()
            ->with(['subject:id,name,department_id,section_id,class_id', 'class:id,name', 'section:id,name', 'department:id,name', 'term:id,name', 'academicSession:id,name', 'schedules'])
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
        $this->ensureExamSetupBelongsToSchool($request, $data);
        $schedule = $data['_schedule'] ?? null;
        unset($data['_schedule']);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($data['delivery_mode']) ? 'offline' : 'online');

        $exam = DB::transaction(function () use ($request, $data, $schedule) {
            $exam = CbtExam::create($data + [
                'created_by' => $request->user()->id,
                'school_id' => $request->user()->school_id,
                'exam_code' => $data['exam_code'] ?? 'CBT-' . now()->format('ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            ]);

            $this->syncSchedule($exam, $schedule);

            return $exam;
        });

        return response()->json([
            'message' => 'CBT exam created.',
            'exam' => $exam->fresh(['subject', 'class', 'section', 'department', 'term', 'academicSession', 'schedules']),
        ], 201);
    }

    public function show(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($exam->delivery_mode) ? 'offline' : 'online');

        return response()->json([
            'exam' => $exam->load([
                'subject:id,name,department_id,section_id,class_id',
                'class:id,name',
                'section:id,name',
                'department:id,name',
                'term:id,name',
                'academicSession:id,name',
                'schedules',
                'sections.questionGroups.questions.options',
                'sections.questions.options',
                'questionGroups.questions.options',
                'questions.options',
                'attempts' => fn ($query) => $query
                    ->with(['student:id,firstname,surname,reg_no,level_id', 'student.level:id,name'])
                    ->with(['answers:id,attempt_id,selected_option_ids,answer_text'])
                    ->withCount(['answers', 'events'])
                    ->latest(),
            ]),
        ]);
    }

    public function update(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        abort_if($exam->status === 'published', 422, 'Published CBT exams must be reopened before editing.');

        $data = $this->normalizeExamData($this->validateExam($request));
        $this->ensureExamSetupBelongsToSchool($request, $data);
        $schedule = $data['_schedule'] ?? null;
        unset($data['_schedule']);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($data['delivery_mode']) ? 'offline' : 'online');

        DB::transaction(function () use ($exam, $data, $schedule) {
            $exam->update($data);
            $this->syncSchedule($exam, $schedule);
        });

        return response()->json([
            'message' => 'CBT exam updated.',
            'exam' => $exam->fresh(['subject', 'class', 'section', 'department', 'term', 'academicSession', 'schedules']),
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

    public function reopen(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($exam->delivery_mode) ? 'offline' : 'online');

        abort_unless($exam->status === 'published', 422, 'Only published CBT exams can be reopened.');
        abort_if($exam->attempts()->exists(), 422, 'This CBT exam cannot be reopened because students have already accessed it.');

        $exam->update([
            'status' => 'draft',
            'published_at' => null,
        ]);

        return response()->json([
            'message' => 'CBT exam reopened for editing.',
            'exam' => $exam->fresh(['subject', 'class', 'section', 'department', 'term', 'academicSession', 'schedules']),
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

    public function importQuestions(Request $request, CbtExam $exam, CbtQuestionImportService $service): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($exam->delivery_mode) ? 'offline' : 'online');
        abort_if($exam->status === 'published', 422, 'Published CBT exams must be reopened before editing.');

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:docx,xlsx,xls,csv', 'max:5120'],
            'preview' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $service->import($exam, $data['file'], $request->boolean('preview'));
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Unable to import questions from this file.',
            ], 422);
        }

        if (($result['summary']['errors_count'] ?? 0) > 0) {
            return response()->json(array_merge($result, [
                'message' => 'Please fix the question file before importing.',
            ]), 422);
        }

        return response()->json($result, $request->boolean('preview') ? 200 : 201);
    }

    public function importWordQuestions(Request $request, CbtExam $exam, CbtQuestionImportService $service): JsonResponse
    {
        return $this->importQuestions($request, $exam, $service);
    }

    public function downloadQuestionTemplate(Request $request, CbtExam $exam, string $format)
    {
        $this->ensureSameSchool($request, $exam);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($exam->delivery_mode) ? 'offline' : 'online');

        $format = strtolower($format);

        if (in_array($format, ['xlsx', 'xls', 'csv'], true)) {
            $headings = [
                'section',
                'type',
                'question',
                'question_image_url',
                'question_table',
                'option_a',
                'option_a_image_url',
                'option_b',
                'option_b_image_url',
                'option_c',
                'option_c_image_url',
                'option_d',
                'option_d_image_url',
                'option_e',
                'option_f',
                'answer',
                'marks',
                'passage',
                'passage_image_url',
                'passage_table',
                'instructions',
                'explanation',
                'difficulty',
            ];

            $rows = [
                [
                    'Objective Questions',
                    'single_choice',
                    'What is a noun?',
                    '',
                    '',
                    'A naming word',
                    '',
                    'An action word',
                    '',
                    'A colour',
                    '',
                    'A place only',
                    '',
                    '',
                    'A',
                    1,
                    '',
                    '',
                    '',
                    '',
                    'A noun names a person, place, animal, or thing.',
                    'normal',
                ],
                [
                    'Comprehension',
                    'single_choice',
                    'Where did Ada go?',
                    '',
                    '',
                    'School',
                    '',
                    'Market',
                    '',
                    'Church',
                    '',
                    'Farm',
                    '',
                    '',
                    'B',
                    1,
                    'Ada went to the market early in the morning to buy books and fresh fruits for her mother.',
                    '',
                    'Item | Quantity | Price
Books | 2 | 500
Fruits | 4 | 200',
                    'Read the passage carefully and answer the questions that follow.',
                    '',
                    'normal',
                ],
                [
                    'True or False',
                    'true_false',
                    'A verb is an action word.',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    'True',
                    1,
                    '',
                    '',
                    '',
                    '',
                    '',
                    'easy',
                ],
                [
                    'Multiple Choice',
                    'multiple_choice',
                    'Which of these are vowels?',
                    '',
                    '',
                    'A',
                    '',
                    'B',
                    '',
                    'E',
                    '',
                    'G',
                    '',
                    '',
                    '',
                    'A,C',
                    1,
                    '',
                    '',
                    '',
                    '',
                    'Multiple choice answers can be separated with comma.',
                    'normal',
                ],
            ];

            $excelFormat = $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
            $extension = $format === 'csv' ? 'csv' : 'xlsx';

            return Excel::download(
                new ResultTemplateExport($headings, $rows),
                'cbt_question_import_template.' . $extension,
                $excelFormat
            );
        }

        abort_unless($format === 'docx', 404);

        $path = $this->buildQuestionWordTemplate();

        return response()->download($path, 'cbt_question_import_template.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    public function downloadOfflineInstaller(Request $request)
    {
        $this->access->ensureCanUse($request->user(), 'offline');

        $path = base_path('offline-installer/dist/GradeQuestOfflineCBTSetup.exe');

        abort_unless(is_file($path), 404, 'Offline CBT installer has not been built yet.');

        return response()->download($path, 'GradeQuestOfflineCBTSetup.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
        ]);
    }

    public function uploadQuestionImage(Request $request, CbtExam $exam): JsonResponse
    {
        $this->ensureSameSchool($request, $exam);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($exam->delivery_mode) ? 'offline' : 'online');
        abort_if($exam->status === 'published', 422, 'Published CBT exams must be reopened before editing.');

        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
        ]);

        $directory = public_path('uploads/cbt/questions');
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $file = $data['image'];
        $name = now()->format('YmdHis') . '_' . Str::random(12) . '.' . $file->getClientOriginalExtension();
        $file->move($directory, $name);

        return response()->json([
            'message' => 'Image uploaded.',
            'url' => url('uploads/cbt/questions/' . $name),
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
        abort_if($exam->attempts()->exists(), 422, 'This question cannot be deleted because students have already started this exam.');
        abort_if($question->answers()->exists(), 422, 'This question cannot be deleted because it already has student answers.');

        $question->delete();
        $exam->update(['total_marks' => (float) $exam->questions()->sum('marks')]);

        return response()->json(['message' => 'CBT question deleted.']);
    }

    public function resetAttempt(Request $request, CbtAttempt $attempt): JsonResponse
    {
        abort_unless((int) $attempt->school_id === (int) $request->user()->school_id, 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $answeredCount = $attempt->answers()
            ->get(['selected_option_ids', 'answer_text'])
            ->filter(fn ($answer) => count($answer->selected_option_ids ?? []) > 0 || trim((string) $answer->answer_text) !== '')
            ->count();
        abort_if($answeredCount > 0, 422, 'This attempt already has saved answers. Only attempts with no answered question can be reset.');

        $metadata = $attempt->metadata ?: [];
        $metadata['reset'] = [
            'reset_by' => $request->user()->id,
            'reset_at' => now()->toDateTimeString(),
            'reason' => $data['reason'] ?? 'No question was answered before the student left the exam.',
        ];

        $attempt->events()->create([
            'exam_id' => $attempt->exam_id,
            'student_id' => $attempt->student_id,
            'school_id' => $attempt->school_id,
            'event_type' => 'attempt_reset',
            'severity' => 'medium',
            'page_url' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata['reset'],
            'occurred_at' => now(),
        ]);

        $attempt->update([
            'status' => 'cancelled',
            'submitted_at' => now(),
            'metadata' => $metadata,
        ]);

        return response()->json([
            'message' => 'Attempt reset. The student can start this exam again.',
            'attempt' => $attempt->fresh(['student'])->loadCount(['answers', 'events']),
        ]);
    }

    public function exportScores(Request $request, CbtExam $exam)
    {
        $this->ensureSameSchool($request, $exam);
        $this->access->ensureCanUse($request->user(), $this->requiresOffline($exam->delivery_mode) ? 'offline' : 'online');

        $exam->load(['class:id,name', 'subject:id,name']);

        $headings = [
            'Student Name',
            'Admission Number',
            'Class',
            'Exam',
            'Subject',
            'Attempt No',
            'Status',
            'Score',
            'Total Marks',
            'Percentage',
            'Answers Saved',
            'Security Events',
            'Started At',
            'Submitted At',
            'Reset Eligible',
        ];

        $rows = CbtAttempt::query()
            ->with(['student:id,firstname,surname,reg_no,level_id', 'student.level:id,name', 'answers:id,attempt_id,selected_option_ids,answer_text'])
            ->withCount(['answers', 'events'])
            ->where('exam_id', $exam->id)
            ->orderBy('student_id')
            ->orderBy('attempt_number')
            ->get()
            ->map(function (CbtAttempt $attempt) use ($exam) {
                $score = (float) $attempt->score;
                $totalMarks = (float) $attempt->total_marks;
                $percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 100, 2) : 0;
                $realAnswersCount = $attempt->answers
                    ->filter(fn ($answer) => count($answer->selected_option_ids ?? []) > 0 || trim((string) $answer->answer_text) !== '')
                    ->count();

                return [
                    trim(($attempt->student->surname ?? '') . ' ' . ($attempt->student->firstname ?? '')),
                    $attempt->student->reg_no ?? '',
                    $exam->class?->name ?? $attempt->student?->level?->name ?? 'All classes',
                    $exam->title,
                    $exam->subject?->name ?? '',
                    $attempt->attempt_number,
                    $attempt->status,
                    $score,
                    $totalMarks,
                    $percentage . '%',
                    $realAnswersCount,
                    (int) ($attempt->events_count ?? 0),
                    optional($attempt->started_at)->toDateTimeString(),
                    optional($attempt->submitted_at)->toDateTimeString(),
                    $realAnswersCount === 0 && $attempt->status !== 'cancelled' ? 'Yes' : 'No',
                ];
            })
            ->values()
            ->all();

        $fileName = 'cbt_scores_exam_' . $exam->id . '_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new ResultTemplateExport($headings, $rows), $fileName, ExcelFormat::XLSX);
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
            'access_code_required' => ['nullable', 'boolean'],
            'access_code' => ['nullable', 'string', 'max:80'],
            'calculator_enabled' => ['nullable', 'boolean'],
            'schedule' => ['nullable', 'array'],
            'schedule.exam_date' => ['nullable', 'date'],
            'schedule.starts_at' => ['nullable', 'date_format:H:i'],
            'schedule.ends_at' => ['nullable', 'date_format:H:i', 'after:schedule.starts_at'],
            'schedule.venue' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'general_instructions' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
        ]);
    }

    private function validateQuestion(Request $request): array
    {
        $data = $request->validate([
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

        foreach (['question_text', 'instructions', 'explanation'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = $this->sanitizeCbtHtml($data[$field]);
            }
        }

        if (! empty($data['options']) && is_array($data['options'])) {
            $data['options'] = array_map(function (array $option) {
                if (isset($option['option_text']) && is_string($option['option_text'])) {
                    $option['option_text'] = $this->sanitizeCbtHtml($option['option_text']);
                }

                return $option;
            }, $data['options']);
        }

        return $data;
    }

    private function normalizeExamData(array $data): array
    {
        foreach (['pass_mark', 'max_attempts', 'duration_minutes'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }

        foreach (['shuffle_questions', 'shuffle_options', 'show_result_after_submit', 'access_code_required', 'calculator_enabled'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }

        $schedule = $data['schedule'] ?? null;
        unset($data['schedule']);

        if (array_key_exists('access_code_required', $data) && ! $data['access_code_required']) {
            $data['access_code'] = null;
        }

        $data['_schedule'] = $schedule;

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

    private function sanitizeCbtHtml(string $html): string
    {
        $allowedTags = '<p><br><strong><b><em><i><u><s><ul><ol><li><blockquote><h2><h3><h4><table><thead><tbody><tr><th><td><img>';
        $clean = strip_tags($html, $allowedTags);
        $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;
        $clean = preg_replace('/(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', '$1="#"', $clean) ?? $clean;

        return trim($clean);
    }

    private function requiresOffline(string $mode): bool
    {
        return in_array($mode, ['offline', 'hybrid'], true);
    }

    private function syncSchedule(CbtExam $exam, ?array $schedule): void
    {
        if (! $schedule || empty($schedule['exam_date']) || empty($schedule['starts_at']) || empty($schedule['ends_at'])) {
            return;
        }

        $exam->schedules()->delete();
        $exam->schedules()->create([
            'school_id' => $exam->school_id,
            'exam_date' => $schedule['exam_date'],
            'starts_at' => $schedule['starts_at'],
            'ends_at' => $schedule['ends_at'],
            'venue' => $schedule['venue'] ?? null,
        ]);
    }

    private function buildQuestionWordTemplate(): string
    {
        if (! class_exists(\ZipArchive::class)) {
            abort(422, 'The PHP Zip extension is required before Word templates can be generated.');
        }

        $paragraphs = [
            'CBT QUESTION IMPORT TEMPLATE',
            'Use this exact structure when preparing CBT questions.',
            'You may insert a Word table or image/diagram directly under a question, option, or passage. It will be imported with the question.',
            '',
            'SECTION: Objective Questions',
            'TYPE: single_choice',
            'MARKS: 1',
            '',
            '1. What is a noun?',
            'A. A naming word',
            'B. An action word',
            'C. A colour',
            'D. A place only',
            'ANSWER: A',
            '',
            'SECTION: Comprehension',
            'PASSAGE: Ada went to the market early in the morning to buy books and fresh fruits for her mother.',
            'END PASSAGE',
            '',
            '2. Where did Ada go?',
            'A. School',
            'B. Market',
            'C. Church',
            'D. Farm',
            'ANSWER: B',
            '',
            'SECTION: True or False',
            'TYPE: true_false',
            '',
            '3. A verb is an action word.',
            'ANSWER: True',
            '',
            'SECTION: Multiple Choice',
            'TYPE: multiple_choice',
            '',
            '4. Which of these are vowels?',
            'A. A',
            'B. B',
            'C. E',
            'D. G',
            'ANSWER: A,C',
        ];

        $path = storage_path('app/cbt_question_import_template_' . uniqid('', true) . '.docx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->docxContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->docxRelsXml());
        $zip->addFromString('word/document.xml', $this->docxDocumentXml($paragraphs));
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->close();

        return $path;
    }

    private function docxDocumentXml(array $paragraphs): string
    {
        $body = collect($paragraphs)->map(function (string $text) {
            $escaped = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');

            return '<w:p><w:r><w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p>';
        })->implode('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body>'
            . '</w:document>';
    }

    private function docxContentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';
    }

    private function docxRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
    }

    private function ensureSameSchool(Request $request, CbtExam $exam): void
    {
        abort_unless((int) $exam->school_id === (int) $request->user()->school_id, 403);
    }

    private function ensureExamSetupBelongsToSchool(Request $request, array $data): void
    {
        $schoolId = (int) $request->user()->school_id;
        $checks = [
            'subject_id' => \App\Models\Subject::class,
            'class_id' => \App\Models\StudentClass::class,
            'section_id' => \App\Models\Section::class,
            'department_id' => \App\Models\Department::class,
            'term_id' => \App\Models\Term::class,
            'academic_session_id' => \App\Models\AcademicSession::class,
        ];

        foreach ($checks as $field => $model) {
            if (empty($data[$field])) {
                continue;
            }

            abort_unless(
                $model::query()->where('school_id', $schoolId)->whereKey($data[$field])->exists(),
                422,
                'Selected exam setup item is not available for this school.'
            );
        }

        if (empty($data['subject_id'])) {
            return;
        }

        $subject = \App\Models\Subject::query()
            ->where('school_id', $schoolId)
            ->whereKey($data['subject_id'])
            ->first();

        abort_unless($subject, 422, 'Selected subject is not available for this school.');

        if (! empty($data['department_id']) && ! empty($subject->department_id)) {
            abort_unless((int) $subject->department_id === (int) $data['department_id'], 422, 'Selected subject does not belong to this department.');
        }

        if (! empty($data['section_id']) && ! empty($subject->section_id)) {
            abort_unless((int) $subject->section_id === (int) $data['section_id'], 422, 'Selected subject does not belong to this section.');
        }

        if (! empty($data['class_id']) && ! empty($subject->class_id)) {
            abort_unless((int) $subject->class_id === (int) $data['class_id'], 422, 'Selected subject does not belong to this class.');
        }
    }
}
