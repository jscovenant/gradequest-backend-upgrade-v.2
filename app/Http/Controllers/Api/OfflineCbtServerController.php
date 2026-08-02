<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfflineCbtAttempt;
use App\Models\OfflineCbtBundle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfflineCbtServerController extends Controller
{
    public function status(): JsonResponse
    {
        $this->ensureEnabled();

        $bundle = $this->activeBundle();

        return response()->json([
            'enabled' => true,
            'server' => $this->serverInfo($requestHost = request()->getHost()),
            'bundle' => $bundle ? $this->bundleSummary($bundle) : null,
            'attempts' => [
                'in_progress' => $bundle ? $bundle->attempts()->where('status', 'in_progress')->count() : 0,
                'submitted' => $bundle ? $bundle->attempts()->where('status', 'submitted')->count() : 0,
            ],
        ]);
    }

    public function importBundle(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'bundle' => ['required', 'array'],
        ]);

        $bundle = $data['bundle'];
        abort_unless(is_array($bundle['students'] ?? null), 422, 'This file does not contain a valid student list.');
        abort_unless(is_array($bundle['exams'] ?? null), 422, 'This file does not contain a valid exam list.');
        abort_if($this->bundleIsExpiredPayload($bundle), 422, 'This offline CBT bundle has expired. Download a fresh bundle before starting the exam.');

        $record = DB::transaction(function () use ($bundle) {
            OfflineCbtBundle::query()->update(['is_active' => false]);

            return OfflineCbtBundle::create([
                'school_id' => $bundle['school']['id'] ?? null,
                'license_id' => $bundle['license']['id'] ?? null,
                'license_key' => $bundle['license']['license_key'] ?? null,
                'school_name' => $bundle['school']['name'] ?? 'School',
                'bundle_signature' => $bundle['bundle_signature'] ?? null,
                'generated_at' => $bundle['generated_at'] ?? null,
                'imported_at' => now(),
                'is_active' => true,
                'payload' => $bundle,
            ]);
        });

        return response()->json([
            'message' => 'Offline CBT bundle imported on this local server.',
            'bundle' => $this->bundleSummary($record),
        ], 201);
    }

    public function lookupStudent(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'student_reg_no' => ['required', 'string', 'max:80'],
        ]);

        $bundle = $this->requireActiveBundle();
        $student = collect($bundle->payload['students'] ?? [])
            ->first(fn ($item) => strtolower((string) ($item['reg_no'] ?? '')) === strtolower(trim($data['student_reg_no'])));

        abort_unless($student, 404, 'Admission number was not found on this local CBT server.');

        $exams = collect($bundle->payload['exams'] ?? [])
            ->filter(fn ($exam) => in_array((int) ($student['id'] ?? 0), array_map('intval', $exam['eligible_student_ids'] ?? []), true))
            ->map(fn ($exam) => $this->examSummary($exam, (int) $student['id'], $bundle->id))
            ->values()
            ->all();

        return response()->json([
            'school' => [
                'id' => $bundle->school_id,
                'name' => $bundle->school_name,
            ],
            'student' => $student,
            'exams' => $exams,
        ]);
    }

    public function startExam(Request $request, int $examId): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
        ]);

        $bundle = $this->requireActiveBundle();
        $student = $this->studentFromBundle($bundle, (int) $data['student_id']);
        $exam = $this->examFromBundle($bundle, $examId);

        abort_unless(in_array((int) $student['id'], array_map('intval', $exam['eligible_student_ids'] ?? []), true), 403, 'This student is not allowed to write this exam.');
        abort_unless($this->examIsOpen($exam), 422, 'This exam is not open at the scheduled time.');

        $existingSubmitted = OfflineCbtAttempt::query()
            ->where('offline_cbt_bundle_id', $bundle->id)
            ->where('exam_id', $examId)
            ->where('student_id', (int) $student['id'])
            ->where('status', 'submitted')
            ->exists();
        abort_if($existingSubmitted, 422, 'This student has already submitted this offline exam.');

        $attempt = OfflineCbtAttempt::query()
            ->where('offline_cbt_bundle_id', $bundle->id)
            ->where('exam_id', $examId)
            ->where('student_id', (int) $student['id'])
            ->where('status', 'in_progress')
            ->first();

        if (! $attempt) {
            $attempt = OfflineCbtAttempt::create([
                'offline_cbt_bundle_id' => $bundle->id,
                'offline_attempt_uuid' => (string) Str::uuid(),
                'school_id' => $bundle->school_id,
                'license_id' => $bundle->license_id,
                'exam_id' => $examId,
                'student_id' => (int) $student['id'],
                'student_reg_no' => $student['reg_no'] ?? null,
                'status' => 'in_progress',
                'started_at' => now(),
                'device_name' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'events_count' => 0,
                'answers' => [],
            ]);
        }

        return response()->json([
            'message' => 'Offline CBT exam started.',
            'attempt' => $this->attemptPayload($attempt),
            'student' => $student,
            'exam' => $this->examPaperPayload($exam),
        ], 201);
    }

    public function saveAnswer(Request $request, string $uuid): JsonResponse
    {
        $this->ensureEnabled();

        $attempt = $this->attemptByUuid($uuid);
        abort_unless($attempt->status === 'in_progress', 422, 'This offline attempt is no longer active.');

        $data = $request->validate([
            'question_id' => ['required', 'integer'],
            'selected_option_ids' => ['nullable', 'array'],
            'selected_option_ids.*' => ['integer'],
            'answer_text' => ['nullable', 'string'],
        ]);

        $bundle = $this->requireActiveBundle();
        $exam = $this->examFromBundle($bundle, (int) $attempt->exam_id);
        $questionIds = collect($exam['questions'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_unless(in_array((int) $data['question_id'], $questionIds, true), 422, 'Selected question does not belong to this exam.');

        $answers = collect($attempt->answers ?? [])
            ->reject(fn ($answer) => (int) ($answer['question_id'] ?? 0) === (int) $data['question_id'])
            ->push([
                'question_id' => (int) $data['question_id'],
                'selected_option_ids' => array_values(array_map('intval', $data['selected_option_ids'] ?? [])),
                'answer_text' => $data['answer_text'] ?? null,
            ])
            ->values()
            ->all();

        $attempt->update(['answers' => $answers]);

        return response()->json([
            'message' => 'Answer saved on local CBT server.',
            'attempt' => $this->attemptPayload($attempt->fresh()),
        ]);
    }

    public function logEvent(Request $request, string $uuid): JsonResponse
    {
        $this->ensureEnabled();

        $attempt = $this->attemptByUuid($uuid);
        abort_unless($attempt->status === 'in_progress', 422, 'This offline attempt is no longer active.');

        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:60'],
            'severity' => ['nullable', 'string', 'max:20'],
            'metadata' => ['nullable', 'array'],
        ]);

        $metadata = $attempt->metadata ?: [];
        $metadata['events'] = $metadata['events'] ?? [];
        $metadata['events'][] = [
            'event_type' => $data['event_type'],
            'severity' => $data['severity'] ?? 'medium',
            'metadata' => $data['metadata'] ?? [],
            'occurred_at' => now()->toIso8601String(),
        ];

        $attempt->update([
            'events_count' => (int) $attempt->events_count + 1,
            'metadata' => $metadata,
        ]);

        return response()->json(['message' => 'Offline CBT event recorded.']);
    }

    public function submitAttempt(string $uuid): JsonResponse
    {
        $this->ensureEnabled();

        $attempt = $this->attemptByUuid($uuid);
        abort_unless($attempt->status === 'in_progress', 422, 'This offline attempt has already been submitted.');

        $attempt->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Offline CBT exam submitted.',
            'attempt' => $this->attemptPayload($attempt->fresh()),
        ]);
    }

    public function exportResults(): StreamedResponse
    {
        $this->ensureEnabled();

        $bundle = $this->requireActiveBundle();
        $attempts = $bundle->attempts()
            ->where('status', 'submitted')
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (OfflineCbtAttempt $attempt) => [
                'offline_attempt_uuid' => $attempt->offline_attempt_uuid,
                'exam_id' => (int) $attempt->exam_id,
                'student_id' => (int) $attempt->student_id,
                'started_at' => optional($attempt->started_at)->toIso8601String(),
                'submitted_at' => optional($attempt->submitted_at)->toIso8601String(),
                'device_name' => $attempt->device_name,
                'ip_address' => $attempt->ip_address,
                'events_count' => (int) $attempt->events_count,
                'answers' => $attempt->answers ?: [],
            ])
            ->values()
            ->all();

        $payload = [
            'sync_reference' => 'offline-sync-' . ($bundle->school_id ?: 'school') . '-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(5)),
            'generated_at' => now()->toIso8601String(),
            'school_id' => $bundle->school_id,
            'license_id' => $bundle->license_id,
            'attempts' => $attempts,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $fileName = 'gradequest_offline_cbt_results_' . now()->format('Ymd_His') . '.json';

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, $fileName, ['Content-Type' => 'application/json']);
    }

    private function ensureEnabled(): void
    {
        abort_unless(app()->environment('local') || (bool) env('OFFLINE_CBT_SERVER_ENABLED', false), 403, 'Offline CBT server is not enabled on this installation.');
    }

    private function activeBundle(): ?OfflineCbtBundle
    {
        return OfflineCbtBundle::query()->where('is_active', true)->latest()->first();
    }

    private function requireActiveBundle(): OfflineCbtBundle
    {
        $bundle = $this->activeBundle();
        abort_unless($bundle, 404, 'No offline CBT bundle has been imported on this local server.');
        abort_if($this->bundleIsExpired($bundle), 422, 'This offline CBT bundle has expired. Import a fresh bundle before students continue.');

        return $bundle;
    }

    private function bundleSummary(OfflineCbtBundle $bundle): array
    {
        return [
            'id' => $bundle->id,
            'school_id' => $bundle->school_id,
            'school_name' => $bundle->school_name,
            'license_id' => $bundle->license_id,
            'license_key' => $bundle->license_key,
            'generated_at' => optional($bundle->generated_at)->toIso8601String(),
            'expires_at' => $this->bundleExpiresAt($bundle)?->toIso8601String(),
            'imported_at' => optional($bundle->imported_at)->toIso8601String(),
            'is_expired' => $this->bundleIsExpired($bundle),
            'fee_policy_snapshot' => $bundle->payload['fee_policy_snapshot'] ?? null,
            'exams_count' => count($bundle->payload['exams'] ?? []),
            'students_count' => count($bundle->payload['students'] ?? []),
            'eligible_students_count' => collect($bundle->payload['exams'] ?? [])
                ->flatMap(fn ($exam) => $exam['eligible_student_ids'] ?? [])
                ->unique()
                ->count(),
            'blocked_students_count' => collect($bundle->payload['exams'] ?? [])
                ->sum(fn ($exam) => (int) ($exam['fee_policy_snapshot']['blocked_students'] ?? 0)),
        ];
    }

    private function serverInfo(string $requestHost): array
    {
        $ips = collect(gethostbynamel(gethostname()) ?: [])
            ->merge([$_SERVER['SERVER_ADDR'] ?? null])
            ->filter()
            ->map(fn ($ip) => trim((string) $ip))
            ->filter(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            ->reject(fn ($ip) => str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.'))
            ->unique()
            ->values()
            ->all();

        $links = collect($ips)
            ->map(fn ($ip) => "http://{$ip}:8088/cbt/offline-runner")
            ->values()
            ->all();

        return [
            'host' => $requestHost,
            'port' => 8088,
            'local_url' => 'http://localhost:8088/cbt/offline-runner',
            'network_ips' => $ips,
            'student_urls' => $links,
        ];
    }

    private function bundleIsExpiredPayload(array $payload): bool
    {
        $expiresAt = $payload['expires_at'] ?? null;

        return $expiresAt && Carbon::parse($expiresAt)->isPast();
    }

    private function bundleExpiresAt(OfflineCbtBundle $bundle): ?Carbon
    {
        $expiresAt = $bundle->payload['expires_at'] ?? null;

        return $expiresAt ? Carbon::parse($expiresAt) : null;
    }

    private function bundleIsExpired(OfflineCbtBundle $bundle): bool
    {
        $expiresAt = $this->bundleExpiresAt($bundle);

        return $expiresAt ? $expiresAt->isPast() : false;
    }

    private function examSummary(array $exam, int $studentId, int $bundleId): array
    {
        $submitted = OfflineCbtAttempt::query()
            ->where('offline_cbt_bundle_id', $bundleId)
            ->where('exam_id', (int) ($exam['id'] ?? 0))
            ->where('student_id', $studentId)
            ->where('status', 'submitted')
            ->exists();

        return [
            'id' => (int) ($exam['id'] ?? 0),
            'title' => $exam['title'] ?? 'Offline CBT Exam',
            'exam_code' => $exam['exam_code'] ?? null,
            'duration_minutes' => (int) ($exam['duration_minutes'] ?? 60),
            'calculator_enabled' => (bool) ($exam['calculator_enabled'] ?? false),
            'questions_count' => count($exam['questions'] ?? []),
            'subject' => $exam['subject'] ?? null,
            'class' => $exam['class'] ?? null,
            'schedules' => $exam['schedules'] ?? [],
            'is_open' => $this->examIsOpen($exam),
            'submitted' => $submitted,
        ];
    }

    private function examPaperPayload(array $exam): array
    {
        $questions = collect($exam['questions'] ?? [])
            ->sortBy(fn ($question) => (int) ($question['sort_order'] ?? 0))
            ->values()
            ->all();

        if ($exam['shuffle_questions'] ?? false) {
            $questions = collect($questions)->shuffle()->values()->all();
        }

        return [
            'id' => (int) ($exam['id'] ?? 0),
            'title' => $exam['title'] ?? 'Offline CBT Exam',
            'exam_code' => $exam['exam_code'] ?? null,
            'duration_minutes' => (int) ($exam['duration_minutes'] ?? 60),
            'shuffle_options' => (bool) ($exam['shuffle_options'] ?? false),
            'calculator_enabled' => (bool) ($exam['calculator_enabled'] ?? false),
            'general_instructions' => $exam['general_instructions'] ?? null,
            'subject' => $exam['subject'] ?? null,
            'class' => $exam['class'] ?? null,
            'question_groups' => $exam['question_groups'] ?? [],
            'questions' => $questions,
        ];
    }

    private function studentFromBundle(OfflineCbtBundle $bundle, int $studentId): array
    {
        $student = collect($bundle->payload['students'] ?? [])
            ->first(fn ($item) => (int) ($item['id'] ?? 0) === $studentId);
        abort_unless($student, 404, 'Student was not found on this local CBT server.');

        return $student;
    }

    private function examFromBundle(OfflineCbtBundle $bundle, int $examId): array
    {
        $exam = collect($bundle->payload['exams'] ?? [])
            ->first(fn ($item) => (int) ($item['id'] ?? 0) === $examId);
        abort_unless($exam, 404, 'Exam was not found on this local CBT server.');

        return $exam;
    }

    private function attemptByUuid(string $uuid): OfflineCbtAttempt
    {
        return OfflineCbtAttempt::query()->where('offline_attempt_uuid', $uuid)->firstOrFail();
    }

    private function attemptPayload(OfflineCbtAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'offline_attempt_uuid' => $attempt->offline_attempt_uuid,
            'status' => $attempt->status,
            'answers' => $attempt->answers ?: [],
            'started_at' => optional($attempt->started_at)->toIso8601String(),
            'submitted_at' => optional($attempt->submitted_at)->toIso8601String(),
        ];
    }

    private function examIsOpen(array $exam): bool
    {
        $schedules = $exam['schedules'] ?? [];
        if (! is_array($schedules) || $schedules === []) {
            return true;
        }

        $today = now()->toDateString();
        $time = now()->format('H:i:s');

        return collect($schedules)->contains(function ($schedule) use ($today, $time) {
            $startsAt = $this->normalizeTime($schedule['starts_at'] ?? null, '00:00:00');
            $endsAt = $this->normalizeTime($schedule['ends_at'] ?? null, '23:59:59');

            return ($schedule['exam_date'] ?? null) === $today
                && $startsAt <= $time
                && $time <= $endsAt;
        });
    }

    private function normalizeTime(?string $value, string $fallback): string
    {
        $value = trim((string) ($value ?: $fallback));

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        return $fallback;
    }
}
