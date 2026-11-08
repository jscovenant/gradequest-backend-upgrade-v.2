<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfflineCbtAttempt;
use App\Models\OfflineCbtBundle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfflineCbtServerController extends Controller
{
    public function status(): JsonResponse
    {
        $this->ensureEnabled();

        $bundle = $this->activeBundle();
        $schoolId = $bundle?->school_id;
        $bundleId = $bundle?->id;

        $inProgressCount = OfflineCbtAttempt::query()
            ->when($schoolId, fn ($q) => $q->where(function ($sq) use ($schoolId, $bundleId) {
                $sq->where('school_id', $schoolId);
                if ($bundleId) $sq->orWhere('offline_cbt_bundle_id', $bundleId);
            }))
            ->where('status', 'in_progress')
            ->count();

        $submittedCount = OfflineCbtAttempt::query()
            ->when($schoolId, fn ($q) => $q->where(function ($sq) use ($schoolId, $bundleId) {
                $sq->where('school_id', $schoolId);
                if ($bundleId) $sq->orWhere('offline_cbt_bundle_id', $bundleId);
            }))
            ->where('status', 'submitted')
            ->count();

        return response()->json([
            'enabled' => true,
            'server' => $this->serverInfo($requestHost = request()->getHost()),
            'bundle' => $bundle ? $this->bundleSummary($bundle) : null,
            'attempts' => [
                'in_progress' => $inProgressCount,
                'submitted' => $submittedCount,
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

            $newBundle = OfflineCbtBundle::create([
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

            // Re-link previous local attempts so they attach to the new active bundle and are never lost or orphaned
            $examIds = collect($bundle['exams'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            if (!empty($examIds)) {
                OfflineCbtAttempt::query()
                    ->whereIn('exam_id', $examIds)
                    ->update([
                        'offline_cbt_bundle_id' => $newBundle->id,
                        'school_id' => $newBundle->school_id,
                        'license_id' => $newBundle->license_id,
                    ]);
            }

            return $newBundle;
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

        // 1. Check if offline package has expired (Platform Fee / Licensing Issue)
        if ($this->bundleIsExpiredPayload($bundle->payload) || $bundle->is_expired) {
            return response()->json([
                'school' => [
                    'id' => $bundle->school_id,
                    'name' => $bundle->school_name,
                ],
                'student' => $student,
                'exams' => [],
                'rejection' => [
                    'reason' => 'platform_fee',
                    'title' => 'Offline CBT Package Expired',
                    'message' => 'The offline CBT license package on this local server has expired. The school administrator must download a fresh package from the SchoolProfit portal.',
                ],
            ]);
        }

        $allBundleExams = collect($bundle->payload['exams'] ?? []);
        $eligibleExams = $allBundleExams
            ->filter(fn ($exam) => in_array((int) ($student['id'] ?? 0), array_map('intval', $exam['eligible_student_ids'] ?? []), true))
            ->map(fn ($exam) => $this->examSummary($exam, (int) $student['id'], $bundle->id))
            ->values()
            ->all();

        $rejection = null;
        if (empty($eligibleExams)) {
            // Check if exams exist in the package for this student's class
            $studentClassId = (int) ($student['class_id'] ?? 0);
            $studentClassName = strtolower(trim((string) ($student['class'] ?? '')));

            $matchingClassExams = $allBundleExams->filter(function ($exam) use ($studentClassId, $studentClassName) {
                if ($studentClassId > 0 && isset($exam['class']['id']) && (int) $exam['class']['id'] === $studentClassId) {
                    return true;
                }
                if ($studentClassName !== '' && isset($exam['class']['name']) && strtolower(trim($exam['class']['name'])) === $studentClassName) {
                    return true;
                }
                return empty($exam['class_id']) && empty($exam['class']['id']);
            });

            if ($matchingClassExams->isNotEmpty()) {
                // Exam exists for student's class, but student was excluded from eligible_student_ids due to fees
                $rejection = [
                    'reason' => 'school_fee',
                    'title' => 'Exam Access Restricted: School Fee Clearance Required',
                    'message' => 'Your student account has an outstanding school fee balance. School policy requires fee clearance before taking exams. Please contact the Bursary or School Administration to update your payment clearance.',
                ];
            } else {
                // No exam was configured or published for this class
                $rejection = [
                    'reason' => 'no_exam_set',
                    'title' => 'No CBT Exam Scheduled for Your Class',
                    'message' => 'There is currently no CBT exam scheduled for your class (' . ($student['class'] ?? 'your level') . '). If an exam is scheduled for today, please alert your invigilator.',
                ];
            }
        }

        return response()->json([
            'school' => [
                'id' => $bundle->school_id,
                'name' => $bundle->school_name,
            ],
            'student' => $student,
            'exams' => $eligibleExams,
            'rejection' => $rejection,
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

        if (!in_array((int) $student['id'], array_map('intval', $exam['eligible_student_ids'] ?? []), true)) {
            return response()->json([
                'status' => 'error',
                'rejection' => [
                    'reason' => 'school_fee',
                    'title' => 'Access Restricted: School Fee Clearance Required',
                    'message' => 'School fee clearance is required before starting this exam. Please contact the school Bursary or Administration.',
                ],
                'message' => 'This student is not cleared by school fee policy to write this exam.',
            ], 403);
        }

        if (!$this->examIsOpen($exam)) {
            return response()->json([
                'status' => 'error',
                'rejection' => [
                    'reason' => 'no_exam_set',
                    'title' => 'Exam Window Closed',
                    'message' => 'This CBT exam is not open at the scheduled time.',
                ],
                'message' => 'This exam is not open at the scheduled time.',
            ], 422);
        }

        // 1. Strict check: Student already completed/submitted in cloud snapshot
        $cloudSubmittedStudentIds = array_map('intval', $exam['submitted_student_ids'] ?? []);
        if (in_array((int) $student['id'], $cloudSubmittedStudentIds, true)) {
            return response()->json([
                'status' => 'error',
                'rejection' => [
                    'reason' => 'already_submitted',
                    'title' => 'Examination Already Completed',
                    'message' => 'You have already completed and submitted this examination. Re-entry is not permitted.',
                ],
                'message' => 'This student has already completed and submitted this examination.',
            ], 422);
        }

        // 2. Strict check: Student already submitted locally on this server (under ANY bundle)
        $existingSubmitted = OfflineCbtAttempt::query()
            ->where('exam_id', $examId)
            ->where('student_id', (int) $student['id'])
            ->where('status', 'submitted')
            ->exists();

        if ($existingSubmitted) {
            return response()->json([
                'status' => 'error',
                'rejection' => [
                    'reason' => 'already_submitted',
                    'title' => 'Examination Already Completed',
                    'message' => 'You have already completed and submitted this examination on this local server. Re-entry is not permitted.',
                ],
                'message' => 'This student has already submitted this offline exam.',
            ], 422);
        }

        // 3. Resume in-progress attempt if exists (from current or previous bundle)
        $attempt = OfflineCbtAttempt::query()
            ->where('exam_id', $examId)
            ->where('student_id', (int) $student['id'])
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        if ($attempt) {
            if ($attempt->offline_cbt_bundle_id !== $bundle->id) {
                $attempt->update(['offline_cbt_bundle_id' => $bundle->id]);
            }
        } else {
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
        $questionRow = collect($exam['questions'] ?? [])->first(fn ($q) => (int) ($q['id'] ?? 0) === (int) $data['question_id']);
        abort_unless($questionRow, 422, 'Selected question does not belong to this exam.');
        abort_if(
            ($questionRow['question_type'] ?? '') === 'theory',
            422,
            'Theory questions are for reading only and must be answered on your paper answer sheet using a pen.'
        );

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

    public function completeObjective(Request $request, string $uuid): JsonResponse
    {
        $this->ensureEnabled();

        $attempt = $this->attemptByUuid($uuid);
        abort_unless($attempt->status === 'in_progress', 422, 'This offline attempt is no longer active.');

        $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
        $metadata['section_a_completed'] = true;
        $metadata['section_a_completed_at'] = now()->toIso8601String();
        $metadata['events'] = $metadata['events'] ?? [];
        $metadata['events'][] = [
            'event_type' => 'section_a_completed',
            'severity' => 'low',
            'metadata' => [],
            'occurred_at' => now()->toIso8601String(),
        ];

        $attempt->update([
            'metadata' => $metadata,
        ]);

        return response()->json([
            'message' => 'Section A (Objective Questions) completed on local CBT server.',
            'attempt' => $this->attemptPayload($attempt->fresh()),
        ]);
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
        $examIds = collect($bundle->payload['exams'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        $attempts = OfflineCbtAttempt::query()
            ->where(function ($q) use ($bundle, $examIds) {
                $q->where('offline_cbt_bundle_id', $bundle->id);
                if (!empty($examIds)) {
                    $q->orWhereIn('exam_id', $examIds);
                }
            })
            ->where('status', 'submitted')
            ->orderBy('submitted_at')
            ->get()
            ->unique('offline_attempt_uuid')
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

    /**
     * Direct Cloud Pull: Pulls exam bundle directly from GradeQuest Cloud without file download
     */
    public function cloudPullBundle(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'cloud_url' => ['nullable', 'string'],
            'license_key' => ['required', 'string'],
            'admin_email' => ['nullable', 'email'],
            'admin_password' => ['nullable', 'string'],
        ]);

        $cloudUrl = rtrim($data['cloud_url'] ?: (config('app.url') && !in_array(parse_url(config('app.url'), PHP_URL_HOST), ['localhost', '127.0.0.1']) ? config('app.url') : 'https://gradequestschool.com.ng'), '/');
        $endpoint = $cloudUrl . '/api/cbt/offline/direct-sync/bundle';

        try {
            $response = Http::withoutVerifying()
                ->timeout(60)
                ->post($endpoint, [
                    'license_key' => trim($data['license_key']),
                    'admin_email' => $data['admin_email'] ?? null,
                    'admin_password' => $data['admin_password'] ?? null,
                ]);

            if ($response->failed()) {
                $err = $response->json('message') ?: ('Cloud server responded with status ' . $response->status());
                return response()->json(['message' => $err], 422);
            }

            $bundle = $response->json('bundle');
            abort_unless(is_array($bundle), 422, 'Cloud server did not return a valid exam package.');
            abort_unless(is_array($bundle['students'] ?? null), 422, 'Cloud bundle contains no student data.');
            abort_unless(is_array($bundle['exams'] ?? null), 422, 'Cloud bundle contains no exam data.');

            $record = DB::transaction(function () use ($bundle) {
                OfflineCbtBundle::query()->update(['is_active' => false]);

                $newBundle = OfflineCbtBundle::create([
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

                // Re-link previous local attempts so they attach to the new active bundle and are never lost or orphaned
                $examIds = collect($bundle['exams'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
                if (!empty($examIds)) {
                    OfflineCbtAttempt::query()
                        ->whereIn('exam_id', $examIds)
                        ->update([
                            'offline_cbt_bundle_id' => $newBundle->id,
                            'school_id' => $newBundle->school_id,
                            'license_id' => $newBundle->license_id,
                        ]);
                }

                return $newBundle;
            });

            $summary = $this->bundleSummary($record);
            return response()->json([
                'message' => 'Exam package successfully pulled directly from Cloud! ' . ($summary['exams_count'] ?? 0) . ' exam(s) and ' . ($summary['students_count'] ?? 0) . ' student(s) installed.',
                'bundle' => $summary,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not connect to Cloud server: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Direct Cloud Push: Pushes completed exam results back to GradeQuest Cloud without file export
     */
    public function cloudPushResults(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $bundle = $this->requireActiveBundle();
        $licenseKey = $request->input('license_key') ?: ($bundle->license_key ?? ($bundle->payload['license']['license_key'] ?? null));
        abort_unless($licenseKey, 422, 'License key not found. Please provide your license key.');

        $data = $request->validate([
            'cloud_url' => ['nullable', 'string'],
            'admin_email' => ['nullable', 'email'],
            'admin_password' => ['nullable', 'string'],
        ]);

        $cloudUrl = rtrim($data['cloud_url'] ?: (config('app.url') && !in_array(parse_url(config('app.url'), PHP_URL_HOST), ['localhost', '127.0.0.1']) ? config('app.url') : 'https://gradequestschool.com.ng'), '/');
        $endpoint = $cloudUrl . '/api/cbt/offline/direct-sync/results';

        $examIds = collect($bundle->payload['exams'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        $attempts = OfflineCbtAttempt::query()
            ->where(function ($q) use ($bundle, $examIds) {
                $q->where('offline_cbt_bundle_id', $bundle->id);
                if (!empty($examIds)) {
                    $q->orWhereIn('exam_id', $examIds);
                }
            })
            ->where('status', 'submitted')
            ->orderBy('submitted_at')
            ->get()
            ->unique('offline_attempt_uuid')
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

        if (empty($attempts)) {
            return response()->json(['message' => 'No submitted student attempts found to push.'], 422);
        }

        $syncReference = 'offline-cloud-push-' . ($bundle->school_id ?: 'school') . '-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(5));

        try {
            $response = Http::withoutVerifying()
                ->timeout(120)
                ->post($endpoint, [
                    'license_key' => trim($licenseKey),
                    'sync_reference' => $syncReference,
                    'attempts' => $attempts,
                    'admin_email' => $data['admin_email'] ?? null,
                    'admin_password' => $data['admin_password'] ?? null,
                ]);

            if ($response->failed()) {
                $err = $response->json('message') ?: ('Cloud sync failed with status ' . $response->status());
                return response()->json(['message' => $err], 422);
            }

            if (Schema::hasColumn('offline_cbt_attempts', 'is_synced')) {
                $bundle->attempts()->where('status', 'submitted')->update(['is_synced' => true]);
            }

            $summary = $response->json('summary') ?? [];
            return response()->json([
                'message' => 'Successfully pushed ' . count($attempts) . ' student result(s) directly to GradeQuest Cloud! Scores recorded in report cards.',
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not connect to Cloud server: ' . $e->getMessage()], 500);
        }
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
            ->map(fn ($ip) => "http://{$ip}:8088/cbt/student")
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
        $examId = (int) ($exam['id'] ?? 0);
        $cloudSubmittedStudentIds = array_map('intval', $exam['submitted_student_ids'] ?? []);
        $submittedInCloud = in_array($studentId, $cloudSubmittedStudentIds, true);
        $submittedLocally = OfflineCbtAttempt::query()
            ->where('exam_id', $examId)
            ->where('student_id', $studentId)
            ->where('status', 'submitted')
            ->exists();
        $submitted = $submittedInCloud || $submittedLocally;

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
        $allQuestions = collect($exam['questions'] ?? [])
            ->sortBy(fn ($question) => (int) ($question['sort_order'] ?? 0))
            ->values();

        $objectiveQuestions = $allQuestions
            ->filter(fn ($q) => ($q['question_type'] ?? 'single_choice') !== 'theory')
            ->values()
            ->all();

        if ($exam['shuffle_questions'] ?? false) {
            $objectiveQuestions = collect($objectiveQuestions)->shuffle()->values()->all();
        }

        $theoryQuestions = $allQuestions
            ->filter(fn ($q) => ($q['question_type'] ?? '') === 'theory')
            ->values()
            ->map(fn ($q, int $idx) => [
                'id' => (int) ($q['id'] ?? 0),
                'number' => $idx + 1,
                'question_type' => 'theory',
                'question_text' => $q['question_text'] ?? '',
                'instructions' => $q['instructions'] ?? null,
                'marks' => (float) ($q['marks'] ?? 0),
                'diagram_url' => $q['diagram_url'] ?? null,
            ])
            ->all();

        $includeTheory = (bool) (($exam['include_theory'] ?? true) && count($theoryQuestions) > 0);

        return [
            'id' => (int) ($exam['id'] ?? 0),
            'title' => $exam['title'] ?? 'Offline CBT Exam',
            'exam_code' => $exam['exam_code'] ?? null,
            'duration_minutes' => (int) ($exam['duration_minutes'] ?? 60),
            'shuffle_options' => (bool) ($exam['shuffle_options'] ?? false),
            'calculator_enabled' => (bool) ($exam['calculator_enabled'] ?? false),
            'general_instructions' => $exam['general_instructions'] ?? null,
            'include_theory' => $includeTheory,
            'theory_instructions' => $exam['theory_instructions'] ?? null,
            'theory_section' => [
                'enabled' => $includeTheory,
                'instructions' => $exam['theory_instructions'] ?? null,
                'total_marks' => (float) collect($theoryQuestions)->sum('marks'),
                'questions' => $includeTheory ? $theoryQuestions : [],
            ],
            'subject' => $exam['subject'] ?? null,
            'class' => $exam['class'] ?? null,
            'question_groups' => $exam['question_groups'] ?? [],
            'questions' => $objectiveQuestions,
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
