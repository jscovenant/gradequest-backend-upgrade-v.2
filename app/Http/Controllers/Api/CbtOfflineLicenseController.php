<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CbtAnswer;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtOfflineLicense;
use App\Models\CbtQuestion;
use App\Models\CbtSyncLog;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Services\CbtAccessService;
use App\Services\SchoolFeeAccessPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CbtOfflineLicenseController extends Controller
{
    public function __construct(
        private readonly CbtAccessService $access,
        private readonly SchoolFeeAccessPolicyService $feeAccessPolicy,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->access->ensureCanUse($request->user(), 'offline');

        return response()->json([
            'licenses' => CbtOfflineLicense::query()
                ->where('school_id', $request->user()->school_id)
                ->latest()
                ->paginate((int) $request->query('per_page', 20)),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $this->access->ensureCanUse($request->user(), 'offline');

        $data = $request->validate([
            'max_students' => ['nullable', 'integer', 'min:0'],
            'max_exams' => ['nullable', 'integer', 'min:0'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $school = SchoolSetting::findOrFail($request->user()->school_id);
        $payload = [
            'school_id' => $school->id,
            'school_name' => $school->school_name,
            'allowed_features' => ['cbt_offline'],
            'max_students' => (int) ($data['max_students'] ?? 0),
            'max_exams' => (int) ($data['max_exams'] ?? 0),
            'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->addDays((int) $data['days'])->endOfDay()->toIso8601String(),
            'issued_at' => now()->toIso8601String(),
        ];

        $licenseKey = 'GQ-CBT-' . strtoupper(Str::random(24));
        $signature = hash_hmac('sha256', json_encode($payload), (string) config('app.key'));

        $license = CbtOfflineLicense::create([
            'school_id' => $school->id,
            'issued_by' => $request->user()->id,
            'license_key' => $licenseKey,
            'allowed_features' => $payload['allowed_features'],
            'max_students' => $payload['max_students'],
            'max_exams' => $payload['max_exams'],
            'starts_at' => now(),
            'expires_at' => now()->addDays((int) $data['days'])->endOfDay(),
            'status' => 'active',
            'signature' => $signature,
            'payload' => $payload,
        ]);

        return response()->json([
            'message' => 'Offline CBT license generated.',
            'license' => $license,
            'download_payload' => [
                'license_key' => $licenseKey,
                'payload' => $payload,
                'signature' => $signature,
            ],
        ], 201);
    }

    public function revoke(Request $request, CbtOfflineLicense $license): JsonResponse
    {
        abort_unless((int) $license->school_id === (int) $request->user()->school_id, 403);
        $this->access->ensureCanUse($request->user(), 'offline');

        $license->update(['status' => 'revoked']);

        return response()->json([
            'message' => 'Offline CBT license revoked.',
            'license' => $license->fresh(),
        ]);
    }

    public function exportBundle(Request $request, CbtOfflineLicense $license): StreamedResponse
    {
        abort_unless((int) $license->school_id === (int) $request->user()->school_id, 403);
        $this->access->ensureCanUse($request->user(), 'offline');
        $this->ensureLicenseIsUsable($license);

        $data = $request->validate([
            'exam_ids' => ['nullable', 'array'],
            'exam_ids.*' => ['integer', 'exists:cbt_exams,id'],
        ]);

        $bundle = $this->buildOfflineBundle($license, $data['exam_ids'] ?? []);
        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $fileName = 'gradequest_offline_cbt_bundle_' . $license->id . '_' . now()->format('Ymd_His') . '.json';

        CbtSyncLog::create([
            'school_id' => $license->school_id,
            'offline_license_id' => $license->id,
            'sync_reference' => 'offline-export-' . Str::uuid(),
            'direction' => 'to_offline_server',
            'status' => 'successful',
            'records_count' => count($bundle['exams']),
            'summary' => [
                'exam_ids' => collect($bundle['exams'])->pluck('id')->all(),
                'students_count' => count($bundle['students']),
            ],
        ]);

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, $fileName, ['Content-Type' => 'application/json']);
    }

    public function syncResults(Request $request, CbtOfflineLicense $license): JsonResponse
    {
        abort_unless((int) $license->school_id === (int) $request->user()->school_id, 403);
        $this->access->ensureCanUse($request->user(), 'offline');
        $this->ensureLicenseIsUsable($license);

        $data = $request->validate([
            'sync_reference' => ['nullable', 'string', 'max:100'],
            'attempts' => ['required', 'array', 'min:1'],
            'attempts.*.offline_attempt_uuid' => ['required', 'string', 'max:80'],
            'attempts.*.exam_id' => ['required', 'integer', 'exists:cbt_exams,id'],
            'attempts.*.student_id' => ['required', 'integer', 'exists:users,id'],
            'attempts.*.started_at' => ['nullable', 'date'],
            'attempts.*.submitted_at' => ['nullable', 'date'],
            'attempts.*.device_name' => ['nullable', 'string', 'max:255'],
            'attempts.*.ip_address' => ['nullable', 'string', 'max:80'],
            'attempts.*.events_count' => ['nullable', 'integer', 'min:0'],
            'attempts.*.answers' => ['nullable', 'array'],
            'attempts.*.answers.*.question_id' => ['required_with:attempts.*.answers', 'integer', 'exists:cbt_questions,id'],
            'attempts.*.answers.*.selected_option_ids' => ['nullable', 'array'],
            'attempts.*.answers.*.selected_option_ids.*' => ['integer', 'exists:cbt_question_options,id'],
            'attempts.*.answers.*.answer_text' => ['nullable', 'string'],
        ]);

        $syncReference = $data['sync_reference'] ?? 'offline-import-' . Str::uuid();
        abort_if(CbtSyncLog::where('sync_reference', $syncReference)->exists(), 422, 'This offline sync file has already been uploaded.');

        $summary = DB::transaction(function () use ($license, $data) {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($data['attempts'] as $row) {
                $exam = CbtExam::where('school_id', $license->school_id)
                    ->whereIn('delivery_mode', ['offline', 'hybrid'])
                    ->findOrFail((int) $row['exam_id']);

                $student = User::where('school_id', $license->school_id)
                    ->whereRaw('LOWER(role) = ?', ['student'])
                    ->findOrFail((int) $row['student_id']);

                if (! $this->studentMatchesExam($student, $exam)) {
                    $skipped++;
                    continue;
                }

                $attempt = CbtAttempt::firstOrNew([
                    'school_id' => $license->school_id,
                    'offline_attempt_uuid' => $row['offline_attempt_uuid'],
                ]);

                $isNew = ! $attempt->exists;
                $attemptNumber = $attempt->attempt_number ?: (((int) CbtAttempt::where('exam_id', $exam->id)
                    ->where('student_id', $student->id)
                    ->max('attempt_number')) + 1);

                $attempt->fill([
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                    'school_id' => $license->school_id,
                    'offline_license_id' => $license->id,
                    'delivery_mode' => 'offline',
                    'attempt_number' => $attemptNumber,
                    'status' => 'submitted',
                    'started_at' => $row['started_at'] ?? now(),
                    'submitted_at' => $row['submitted_at'] ?? now(),
                    'synced_at' => now(),
                    'device_name' => $row['device_name'] ?? null,
                    'ip_address' => $row['ip_address'] ?? null,
                    'total_marks' => (float) $exam->questions()->sum('marks'),
                    'metadata' => [
                        'source' => 'offline_sync',
                        'events_count' => (int) ($row['events_count'] ?? 0),
                    ],
                ]);
                $attempt->save();

                $this->syncAttemptAnswers($attempt, $row['answers'] ?? []);

                $attempt->update([
                    'score' => (float) $attempt->answers()->sum('score'),
                    'total_marks' => (float) $exam->questions()->sum('marks'),
                ]);

                $isNew ? $created++ : $updated++;
            }

            return compact('created', 'updated', 'skipped');
        });

        CbtSyncLog::create([
            'school_id' => $license->school_id,
            'offline_license_id' => $license->id,
            'sync_reference' => $syncReference,
            'direction' => 'from_offline_server',
            'status' => 'successful',
            'records_count' => (int) ($summary['created'] + $summary['updated']),
            'summary' => $summary,
        ]);

        $license->update(['last_synced_at' => now()]);

        return response()->json([
            'message' => 'Offline CBT results synced successfully.',
            'summary' => $summary,
        ]);
    }

    private function ensureLicenseIsUsable(CbtOfflineLicense $license): void
    {
        abort_unless($license->status === 'active', 422, 'This offline CBT license is not active.');
        abort_if($license->expires_at && $license->expires_at->isPast(), 422, 'This offline CBT license has expired.');
    }

    private function buildOfflineBundle(CbtOfflineLicense $license, array $examIds): array
    {
        $school = SchoolSetting::findOrFail($license->school_id);
        $generatedAt = now();
        $validHours = max(1, (int) env('OFFLINE_CBT_BUNDLE_VALID_HOURS', 24));
        $expiresAt = $generatedAt->copy()->addHours($validHours);
        $exams = CbtExam::query()
            ->with([
                'subject:id,name',
                'class:id,name',
                'section:id,name',
                'department:id,name',
                'term:id,name',
                'academicSession:id,name',
                'questionGroups.questions.options',
                'questions.options',
                'schedules',
            ])
            ->where('school_id', $license->school_id)
            ->where('status', 'published')
            ->whereIn('delivery_mode', ['offline', 'hybrid'])
            ->when($examIds !== [], fn ($query) => $query->whereIn('id', $examIds))
            ->latest('published_at')
            ->limit($license->max_exams > 0 ? (int) $license->max_exams : 100)
            ->get();

        $students = User::query()
            ->with(['level:id,name', 'section:id,name', 'department:id,name'])
            ->where('school_id', $license->school_id)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->limit($license->max_students > 0 ? (int) $license->max_students : 10000)
            ->get()
            ->map(fn (User $student) => [
                'id' => $student->id,
                'name' => trim(($student->surname ?? '') . ' ' . ($student->firstname ?? '')),
                'reg_no' => $student->reg_no,
                'class_id' => $student->level_id,
                'class' => $student->level?->name,
                'section_id' => $student->section_id,
                'section' => $student->section?->name,
                'department_id' => $student->department_id,
                'department' => $student->department?->name,
            ])
            ->values();

        $payload = [
            'version' => 1,
            'generated_at' => $generatedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'fee_policy_snapshot' => [
                'included' => true,
                'generated_at' => $generatedAt->toIso8601String(),
                'valid_hours' => $validHours,
                'message' => 'Student exam access was checked against the school fee policy when this bundle was generated.',
            ],
            'school' => [
                'id' => $school->id,
                'name' => $school->school_name,
            ],
            'license' => [
                'id' => $license->id,
                'license_key' => $license->license_key,
                'starts_at' => optional($license->starts_at)->toIso8601String(),
                'expires_at' => optional($license->expires_at)->toIso8601String(),
                'signature' => $license->signature,
            ],
            'students' => $students->all(),
            'exams' => $exams->map(fn (CbtExam $exam) => $this->examBundlePayload($exam))->values()->all(),
        ];

        return $payload + [
            'bundle_signature' => hash_hmac('sha256', json_encode($payload), (string) config('app.key')),
        ];
    }

    private function examBundlePayload(CbtExam $exam): array
    {
        $matchedStudentIds = $this->matchedStudentIdsForExam($exam);
        $eligibleStudentIds = $this->eligibleStudentIdsForExam($exam, $matchedStudentIds);

        return [
            'id' => $exam->id,
            'title' => $exam->title,
            'exam_code' => $exam->exam_code,
            'duration_minutes' => (int) $exam->duration_minutes,
            'max_attempts' => (int) $exam->max_attempts,
            'shuffle_questions' => (bool) $exam->shuffle_questions,
            'shuffle_options' => (bool) $exam->shuffle_options,
            'show_result_after_submit' => (bool) $exam->show_result_after_submit,
            'calculator_enabled' => (bool) $exam->calculator_enabled,
            'general_instructions' => $exam->general_instructions,
            'subject' => $exam->subject,
            'class_id' => $exam->class_id,
            'class' => $exam->class,
            'section_id' => $exam->section_id,
            'section' => $exam->section,
            'department_id' => $exam->department_id,
            'department' => $exam->department,
            'term_id' => $exam->term_id,
            'term' => $exam->term,
            'academic_session_id' => $exam->academic_session_id,
            'academic_session' => $exam->academicSession,
            'schedules' => $exam->schedules,
            'eligible_student_ids' => $eligibleStudentIds,
            'fee_policy_snapshot' => [
                'matched_students' => count($matchedStudentIds),
                'eligible_students' => count($eligibleStudentIds),
                'blocked_students' => max(0, count($matchedStudentIds) - count($eligibleStudentIds)),
            ],
            'question_groups' => $exam->questionGroups->map(fn ($group) => [
                'id' => $group->id,
                'exam_id' => $group->exam_id,
                'section_id' => $group->section_id,
                'group_type' => $group->group_type,
                'title' => $group->title,
                'instructions' => $group->instructions,
                'passage' => $group->passage,
                'sort_order' => (int) $group->sort_order,
            ])->values(),
            'questions' => $exam->questions->map(fn ($question) => [
                'id' => $question->id,
                'exam_id' => $question->exam_id,
                'section_id' => $question->section_id,
                'question_group_id' => $question->question_group_id,
                'question_type' => $question->question_type,
                'question_text' => $question->question_text,
                'instructions' => $question->instructions,
                'marks' => (float) $question->marks,
                'sort_order' => (int) $question->sort_order,
                'options' => $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'option_text' => $option->option_text,
                    'sort_order' => (int) $option->sort_order,
                ])->values(),
            ])->values(),
        ];
    }

    private function matchedStudentIdsForExam(CbtExam $exam): array
    {
        return User::query()
            ->where('school_id', $exam->school_id)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->when($exam->class_id, fn ($query) => $query->where('level_id', $exam->class_id))
            ->when($exam->section_id, fn ($query) => $query->where('section_id', $exam->section_id))
            ->when($exam->department_id, fn ($query) => $query->where('department_id', $exam->department_id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function eligibleStudentIdsForExam(CbtExam $exam, ?array $matchedStudentIds = null): array
    {
        $matchedStudentIds ??= $this->matchedStudentIdsForExam($exam);

        return User::query()
            ->where('school_id', $exam->school_id)
            ->whereIn('id', $matchedStudentIds)
            ->get(['id', 'school_id'])
            ->filter(fn (User $student) => ! $this->feeAccessPolicy->assertCbtAccess(
                (int) $exam->school_id,
                (int) $student->id,
                $exam->academic_session_id ? (int) $exam->academic_session_id : null,
                $exam->term_id ? (int) $exam->term_id : null,
            ))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function syncAttemptAnswers(CbtAttempt $attempt, array $answers): void
    {
        foreach ($answers as $row) {
            $question = CbtQuestion::with('options')
                ->where('exam_id', $attempt->exam_id)
                ->findOrFail((int) $row['question_id']);

            $selectedOptionIds = collect($row['selected_option_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all();
            $answerText = $row['answer_text'] ?? null;
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

    private function studentMatchesExam(User $student, CbtExam $exam): bool
    {
        if ($exam->class_id && (int) $exam->class_id !== (int) $student->level_id) {
            return false;
        }

        if ($exam->section_id && (int) $exam->section_id !== (int) $student->section_id) {
            return false;
        }

        if ($exam->department_id && (int) $exam->department_id !== (int) $student->department_id) {
            return false;
        }

        $feeBlock = $this->feeAccessPolicy->assertCbtAccess(
            (int) $exam->school_id,
            (int) $student->id,
            $exam->academic_session_id ? (int) $exam->academic_session_id : null,
            $exam->term_id ? (int) $exam->term_id : null,
        );

        return ! $feeBlock;
    }
}
