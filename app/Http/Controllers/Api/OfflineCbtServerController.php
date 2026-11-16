<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CbtStudentSession;
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

            // Update school and license references without overwriting original originating bundle IDs
            $examIds = collect($bundle['exams'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            if (!empty($examIds)) {
                OfflineCbtAttempt::query()
                    ->whereIn('exam_id', $examIds)
                    ->update([
                        'school_id' => $newBundle->school_id,
                        'license_id' => $newBundle->license_id,
                    ]);
            }

            // Synchronize active session and term from bundle:
            $activeTermId = (int) ($bundle['active_term_id'] ?? ($bundle['school']['current_term_id'] ?? 0));
            $activeSessionId = (int) ($bundle['active_academic_session_id'] ?? ($bundle['school']['academic_session_id'] ?? 0));
            if ($activeTermId > 0 && Schema::hasTable('terms')) {
                DB::table('terms')->where('school_id', $bundle['school']['id'] ?? 0)->update(['status' => 'Inactive']);
                DB::table('terms')->where('id', $activeTermId)->update(['status' => 'Active']);
            }
            if ($activeSessionId > 0 && Schema::hasTable('academic_sessions')) {
                $hasSessionSchoolId = Schema::hasColumn('academic_sessions', 'school_id');
                $q = DB::table('academic_sessions');
                if ($hasSessionSchoolId) {
                    $q->where('school_id', $bundle['school']['id'] ?? 0);
                }
                $q->update(['is_current' => 0]);
                DB::table('academic_sessions')->where('id', $activeSessionId)->update(['is_current' => 1]);
            }

            // Synchronize administrative resets from bundle:
            foreach ($bundle['exams'] ?? [] as $bExam) {
                $bExamId = (int) ($bExam['id'] ?? 0);
                $resetIds = array_map('intval', $bExam['reset_student_ids'] ?? []);
                if ($bExamId > 0 && !empty($resetIds)) {
                    OfflineCbtAttempt::query()
                        ->where('exam_id', $bExamId)
                        ->whereIn('student_id', $resetIds)
                        ->where('status', '!=', 'cancelled')
                        ->get()
                        ->each(function (OfflineCbtAttempt $att) {
                            $meta = is_array($att->metadata) ? $att->metadata : [];
                            $meta['reset'] = [
                                'reset_at' => now()->toIso8601String(),
                                'reset_by' => 'Cloud Portal Sync',
                                'reason' => 'Administrator reset on GradeQuest cloud portal.',
                            ];
                            $att->update([
                                'status' => 'cancelled',
                                'metadata' => $meta,
                            ]);
                        });
                }
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

        // 2. Check individual student platform fee clearance
        if (isset($student['platform_fee_cleared']) && ! $student['platform_fee_cleared']) {
            return response()->json([
                'school' => [
                    'id' => $bundle->school_id,
                    'name' => $bundle->school_name,
                ],
                'student' => $student,
                'exams' => [],
                'rejection' => $student['platform_fee_rejection'] ?? [
                    'reason' => 'platform_fee',
                    'title' => 'Exam Access Restricted: Platform Fee Clearance Required',
                    'message' => 'Your student account has an outstanding SchoolProfit platform fee balance. Please contact the school Bursary or Administration to clear the platform fee before taking CBT exams.',
                ],
            ]);
        }

        // 3. Prevent multiple devices from logging into the same student account simultaneously
        $studentId = (int) $student['id'];
        $clientSessionToken = trim((string) ($request->header('X-Student-Session-Token') ?: $request->input('session_token') ?: ''));
        $deviceFingerprint = (string) ($request->input('device_fingerprint') ?: md5($request->ip() . '|' . ($request->userAgent() ?: 'device')));
        $deviceName = (string) ($request->input('device_name') ?: ($request->userAgent() ? substr($request->userAgent(), 0, 100) : 'Computer'));
        $activeSessionToken = null;

        if (Schema::hasTable('cbt_student_sessions')) {
            $lockKey = "cbt_student_session_lock_{$studentId}";
            try {
                DB::statement("SELECT GET_LOCK(?, 10)", [$lockKey]);

                $existing = CbtStudentSession::where('student_id', $studentId)
                    ->where('is_active', true)
                    ->latest('id')
                    ->first();

                if ($existing) {
                    $staleThreshold = now()->subMinutes(15);
                    if ($existing->last_activity_at < $staleThreshold) {
                        // Stale session from a crashed or powered-down computer: automatically retire
                        $existing->update([
                            'is_active' => false,
                            'released_at' => now(),
                            'release_reason' => 'inactivity_timeout',
                        ]);
                        $existing = null;
                    } elseif ($clientSessionToken !== '' && hash_equals($existing->session_token, $clientSessionToken)) {
                        // Same active session refreshing or re-looking up: update heartbeat
                        $existing->update([
                            'last_activity_at' => now(),
                            'ip_address' => $request->ip(),
                        ]);
                        $activeSessionToken = $existing->session_token;
                    } else {
                        // Another computer attempting simultaneous login! STRICT REJECTION REQUIRED!
                        abort(response()->json([
                            'status' => 'error',
                            'error_code' => 'simultaneous_login_blocked',
                            'message' => 'This student account is already logged in on another computer. Simultaneous login is not permitted. Please log out from the existing device before attempting to log in again.',
                            'rejection' => [
                                'reason' => 'simultaneous_login',
                                'title' => 'Simultaneous Login Not Permitted',
                                'message' => 'This student account is already logged in on another computer. Simultaneous login is not permitted. Please log out from the existing device before attempting to log in again.',
                            ],
                            'active_session' => [
                                'device_name' => $existing->device_name,
                                'ip_address' => $existing->ip_address,
                                'last_activity_at' => optional($existing->last_activity_at)->toIso8601String(),
                            ],
                        ], 403));
                    }
                }

                if (!$existing) {
                    $newToken = 'gq_sess_' . Str::random(40);
                    CbtStudentSession::create([
                        'school_id' => $bundle->school_id,
                        'student_id' => $studentId,
                        'student_reg_no' => (string) ($student['reg_no'] ?? ''),
                        'session_token' => $newToken,
                        'delivery_mode' => 'offline',
                        'device_fingerprint' => $deviceFingerprint,
                        'device_name' => $deviceName,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'is_active' => true,
                        'login_at' => now(),
                        'last_activity_at' => now(),
                    ]);
                    $activeSessionToken = $newToken;
                }
            } finally {
                DB::statement("SELECT RELEASE_LOCK(?)", [$lockKey]);
            }
        }

        $allBundleExams = collect($bundle->payload['exams'] ?? []);
        $studentClassId = (int) ($student['class_id'] ?? 0);

        // Determine active academic session and active term from bundle or local database
        $activeSessionId = (int) ($bundle->payload['active_academic_session_id']
            ?? ($bundle->payload['school']['academic_session_id']
            ?? ($bundle->payload['academic_session']['id'] ?? 0)));

        $activeTermId = (int) ($bundle->payload['active_term_id']
            ?? ($bundle->payload['school']['current_term_id']
            ?? ($bundle->payload['term']['id'] ?? 0)));

        if ($activeTermId <= 0 && Schema::hasTable('terms')) {
            $activeTermId = (int) (DB::table('terms')
                ->where('school_id', $bundle->school_id)
                ->whereNull('archived_at')
                ->where(function ($q) {
                    $q->where('status', 'Active')->orWhere('status', 'active');
                })
                ->orderByRaw('COALESCE(sort_order, 999999) ASC')
                ->orderBy('id')
                ->value('id') ?: 0);
        }

        if ($activeSessionId <= 0 && Schema::hasTable('academic_sessions')) {
            $hasSessionSchoolId = Schema::hasColumn('academic_sessions', 'school_id');
            $q = DB::table('academic_sessions')->whereNull('archived_at');
            if ($hasSessionSchoolId) {
                $q->where('school_id', $bundle->school_id);
            }
            $activeSessionId = (int) ((clone $q)->where('is_current', 1)->value('id') ?: (clone $q)->orderByDesc('id')->value('id') ?: 0);
        }

        // Strict security & class isolation: Students can ONLY see exams belonging to their current class, active session, and active term!
        $matchingClassExams = $allBundleExams
            ->filter(function ($exam) use ($student, $studentClassId, $activeSessionId, $activeTermId) {
                // Class matching
                $examClassId = (int) ($exam['class_id'] ?? ($exam['class']['id'] ?? 0));
                if ($studentClassId <= 0 || $examClassId <= 0 || $examClassId !== $studentClassId) {
                    return false;
                }

                // Arm / Section matching (if configured on exam)
                $examSectionId = (int) ($exam['section_id'] ?? ($exam['section']['id'] ?? 0));
                $studentSectionId = (int) ($student['section_id'] ?? 0);
                if ($examSectionId > 0 && $studentSectionId > 0 && $examSectionId !== $studentSectionId) {
                    return false;
                }

                // Department matching (if configured on exam)
                $examDeptId = (int) ($exam['department_id'] ?? ($exam['department']['id'] ?? 0));
                $studentDeptId = (int) ($student['department_id'] ?? 0);
                if ($examDeptId > 0 && $studentDeptId > 0 && $examDeptId !== $studentDeptId) {
                    return false;
                }

                // Term matching: ONLY show examinations for current active term!
                if ($activeTermId > 0) {
                    $examTermId = (int) ($exam['term_id'] ?? ($exam['term']['id'] ?? 0));
                    if ($examTermId > 0 && $examTermId !== $activeTermId) {
                        return false;
                    }
                }

                // Academic Session matching: ONLY show examinations for current active session!
                if ($activeSessionId > 0) {
                    $examSessionId = (int) ($exam['academic_session_id'] ?? ($exam['academic_session']['id'] ?? 0));
                    if ($examSessionId > 0 && $examSessionId !== $activeSessionId) {
                        return false;
                    }
                }

                return true;
            });

        $eligibleExams = $matchingClassExams
            ->filter(fn ($exam) => in_array((int) ($student['id'] ?? 0), array_map('intval', $exam['eligible_student_ids'] ?? []), true))
            ->map(fn ($exam) => $this->examSummary($exam, (int) $student['id'], $bundle->id))
            ->sort(function ($a, $b) {
                // 1. Unsubmitted exams appear BEFORE submitted exams
                if ($a['submitted'] !== $b['submitted']) {
                    return $a['submitted'] ? 1 : -1;
                }

                // 2. For unsubmitted exams: Open now comes first, upcoming next, expired last
                if (!$a['submitted']) {
                    $priorityA = $a['is_open'] ? 0 : (!empty($a['is_upcoming']) ? 1 : 2);
                    $priorityB = $b['is_open'] ? 0 : (!empty($b['is_upcoming']) ? 1 : 2);
                    if ($priorityA !== $priorityB) {
                        return $priorityA <=> $priorityB;
                    }
                }

                // 3. Subject / Title ascending
                $subjectA = strtolower($a['subject']['name'] ?? $a['title'] ?? '');
                $subjectB = strtolower($b['subject']['name'] ?? $b['title'] ?? '');
                return strcmp($subjectA, $subjectB);
            })
            ->values()
            ->all();

        $rejection = null;
        if (empty($eligibleExams)) {
            if ($matchingClassExams->isNotEmpty()) {
                // Exam exists for student's class, active session, and active term, but student was excluded from eligible_student_ids due to fees
                $rejection = [
                    'reason' => 'school_fee',
                    'title' => 'Exam Access Restricted: School Fee Clearance Required',
                    'message' => 'Your student account has an outstanding school fee balance. School policy requires fee clearance before taking exams. Please contact the Bursary or School Administration to update your payment clearance.',
                ];
            } else {
                // No exam was configured or published for this class in current active session/term
                $rejection = [
                    'reason' => 'no_exam_set',
                    'title' => 'No CBT Exam Scheduled for Your Class',
                    'message' => 'There is currently no CBT exam scheduled for your class (' . ($student['class'] ?? 'your level') . ') for the current academic term. If an exam is scheduled for today, please alert your invigilator.',
                ];
            }
        }

        return response()->json([
            'school' => [
                'id' => $bundle->school_id,
                'name' => $bundle->school_name,
                'active_academic_session_id' => $activeSessionId ?: null,
                'active_term_id' => $activeTermId ?: null,
            ],
            'student' => $student,
            'exams' => $eligibleExams,
            'rejection' => $rejection,
            'student_session_token' => $activeSessionToken,
        ])->header('X-Student-Session-Token', (string) $activeSessionToken);
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

        // Strict security & class isolation: Verify student's class matches exam's class
        $studentClassId = (int) ($student['class_id'] ?? 0);
        $examClassId = (int) ($exam['class_id'] ?? ($exam['class']['id'] ?? 0));
        if ($studentClassId <= 0 || $examClassId <= 0 || $examClassId !== $studentClassId) {
            return response()->json([
                'status' => 'error',
                'rejection' => [
                    'reason' => 'class_mismatch',
                    'title' => 'Access Denied: Class Mismatch',
                    'message' => 'This examination is assigned to a different class, not your class.',
                ],
                'message' => 'This examination is assigned to a different class, not your class.',
            ], 403);
        }

        // Strict security: Verify individual student platform fee clearance
        if (isset($student['platform_fee_cleared']) && ! $student['platform_fee_cleared']) {
            return response()->json([
                'status' => 'error',
                'rejection' => $student['platform_fee_rejection'] ?? [
                    'reason' => 'platform_fee',
                    'title' => 'Access Denied: Platform Fee Clearance Required',
                    'message' => 'SchoolProfit platform fee for this academic period has not been settled. Please contact school administration to clear the platform fee before taking CBT exams.',
                ],
                'message' => 'SchoolProfit platform fee is unpaid. Contact school administration to clear the platform fee before taking CBT exams.',
            ], 402);
        }

        if (!in_array((int) $student['id'], array_map('intval', $exam['eligible_student_ids'] ?? []), true)) {
            $isPlatformUnpaid = isset($student['platform_fee_cleared']) && ! $student['platform_fee_cleared'];
            return response()->json([
                'status' => 'error',
                'rejection' => [
                    'reason' => $isPlatformUnpaid ? 'platform_fee' : 'school_fee',
                    'title' => $isPlatformUnpaid ? 'Access Denied: Platform Fee Clearance Required' : 'Access Restricted: School Fee Clearance Required',
                    'message' => $isPlatformUnpaid
                        ? 'SchoolProfit platform fee for this academic period has not been settled. Please contact school administration to clear the platform fee before taking CBT exams.'
                        : 'School fee clearance is required before starting this exam. Please contact the school Bursary or Administration.',
                ],
                'message' => $isPlatformUnpaid
                    ? 'SchoolProfit platform fee is unpaid. Contact school administration to clear the platform fee before taking CBT exams.'
                    : 'This student is not cleared by fee policy to write this exam.',
            ], $isPlatformUnpaid ? 402 : 403);
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

        // Check if candidate attempt was reset in cloud
        $cloudResetStudentIds = array_map('intval', $exam['reset_student_ids'] ?? []);
        if (in_array((int) $student['id'], $cloudResetStudentIds, true)) {
            OfflineCbtAttempt::query()
                ->where('exam_id', $examId)
                ->where('student_id', (int) $student['id'])
                ->where('status', 'submitted')
                ->get()
                ->each(function (OfflineCbtAttempt $att) {
                    $meta = is_array($att->metadata) ? $att->metadata : [];
                    $meta['reset'] = [
                        'reset_at' => now()->toIso8601String(),
                        'reset_by' => 'Cloud Portal Sync',
                        'reason' => 'Synchronized attempt reset from SchoolProfit Cloud.',
                    ];
                    $att->update(['status' => 'cancelled', 'metadata' => $meta]);
                });
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

        $durationMinutes = (int) ($exam['duration_minutes'] ?? 60);
        if ($durationMinutes <= 0) {
            $durationMinutes = 60;
        }

        // 3. Resume in-progress attempt if exists (from current or previous bundle)
        $attempt = OfflineCbtAttempt::query()
            ->where('exam_id', $examId)
            ->where('student_id', (int) $student['id'])
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        $incomingLockToken = $request->input('session_lock_token') ?: $request->header('X-CBT-Session-Lock');

        if ($attempt) {
            $elapsedSeconds = $attempt->started_at ? (int) $attempt->started_at->diffInSeconds(now()) : 0;
            $maxAllowedSeconds = ($durationMinutes * 60) + 120; // 2 min grace

            if ($elapsedSeconds > $maxAllowedSeconds) {
                $answeredCount = collect($attempt->answers ?? [])
                    ->filter(fn ($a) => !empty($a['selected_option_ids']) || trim((string)($a['answer_text'] ?? '')) !== '')
                    ->count();

                if ($answeredCount === 0) {
                    // Stale abandoned attempt without answers: cancel and start fresh so candidate is not trapped with 0 seconds
                    $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
                    $meta['reset'] = [
                        'reset_at' => now()->toIso8601String(),
                        'reset_by' => 'Auto-recovery (abandoned session expired with 0 answers)',
                        'reason' => 'Candidate resumed expired session with no recorded answers. Attempt reset automatically.',
                    ];
                    $attempt->update(['status' => 'cancelled', 'metadata' => $meta]);
                    $attempt = null;
                } else {
                    // Exam had answers and time expired: finalize submission
                    $attempt->update([
                        'status' => 'submitted',
                        'submitted_at' => $attempt->started_at ? $attempt->started_at->copy()->addMinutes($durationMinutes) : now(),
                    ]);

                    return response()->json([
                        'status' => 'error',
                        'rejection' => [
                            'reason' => 'already_submitted',
                            'title' => 'Examination Time Expired',
                            'message' => 'Your examination time has expired and your previous attempt has been submitted.',
                        ],
                        'message' => 'This examination has already been completed and submitted.',
                    ], 422);
                }
            } else {
                if ($attempt->offline_cbt_bundle_id !== $bundle->id) {
                    $attempt->update(['offline_cbt_bundle_id' => $bundle->id]);
                }

                // Check multiple-device session lock
                $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
                $activeLockToken = $meta['session_lock_token'] ?? null;
                $deviceTransferAuthorized = !empty($meta['device_transfer_authorized']);

                if ($activeLockToken) {
                    $tokenMatches = $incomingLockToken && hash_equals((string) $activeLockToken, (string) $incomingLockToken);
                    if (! $tokenMatches) {
                        if ($deviceTransferAuthorized) {
                            // Authorized device transfer by administrator: bind new device
                            $newLockToken = bin2hex(random_bytes(24));
                            $meta['session_lock_token'] = $newLockToken;
                            $meta['device_transfer_authorized'] = false;
                            $meta['device_transfer_completed_at'] = now()->toIso8601String();
                            $meta['events'] = $meta['events'] ?? [];
                            $meta['events'][] = [
                                'event_type' => 'device_transfer_completed',
                                'severity' => 'medium',
                                'metadata' => [
                                    'new_device' => $request->userAgent(),
                                    'new_ip' => $request->ip(),
                                ],
                                'occurred_at' => now()->toIso8601String(),
                            ];
                            $attempt->update([
                                'device_name' => $request->userAgent(),
                                'ip_address' => $request->ip(),
                                'metadata' => $meta,
                            ]);
                        } else {
                            // Reject multiple-device access
                            $meta['events'] = $meta['events'] ?? [];
                            $meta['events'][] = [
                                'event_type' => 'duplicate_device_rejected',
                                'severity' => 'high',
                                'metadata' => [
                                    'rejected_device' => $request->userAgent(),
                                    'rejected_ip' => $request->ip(),
                                    'attempted_at' => now()->toIso8601String(),
                                ],
                                'occurred_at' => now()->toIso8601String(),
                            ];
                            $attempt->update(['metadata' => $meta]);

                            return response()->json([
                                'status' => 'error',
                                'rejection' => [
                                    'reason' => 'multiple_device_access',
                                    'title' => 'Active Session on Another Device',
                                    'message' => 'This account already has an active examination session on another device. Multiple-device examination access is not permitted.',
                                ],
                                'message' => 'This account already has an active examination session on another device. Multiple-device examination access is not permitted.',
                            ], 403);
                        }
                    } else {
                        // Same device reconnect / reload
                        $meta['last_reconnect_at'] = now()->toIso8601String();
                        $attempt->update(['metadata' => $meta]);
                    }
                } else {
                    $newLockToken = bin2hex(random_bytes(24));
                    $meta['session_lock_token'] = $newLockToken;
                    $attempt->update(['metadata' => $meta]);
                }
            }
        }

        if (! $attempt) {
            $lockToken = bin2hex(random_bytes(24));
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
                'metadata' => [
                    'session_lock_token' => $lockToken,
                    'device_fingerprint' => hash('sha256', ($request->ip() ?? '') . '|' . ($request->userAgent() ?? '')),
                    'created_device' => $request->userAgent(),
                    'created_ip' => $request->ip(),
                ],
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

        $incomingLockToken = $request->input('session_lock_token') ?: $request->header('X-CBT-Session-Lock');
        $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
        $activeLockToken = $meta['session_lock_token'] ?? null;
        if ($activeLockToken && (! $incomingLockToken || ! hash_equals((string) $activeLockToken, (string) $incomingLockToken))) {
            return response()->json([
                'status' => 'error',
                'rejection' => [
                    'reason' => 'multiple_device_access',
                    'title' => 'Active Session on Another Device',
                    'message' => 'This account already has an active examination session on another device. Multiple-device examination access is not permitted.',
                ],
                'message' => 'This account already has an active examination session on another device. Multiple-device examination access is not permitted.',
            ], 403);
        }

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

        $incomingLockToken = $request->input('session_lock_token') ?: $request->header('X-CBT-Session-Lock');
        $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
        $activeLockToken = $meta['session_lock_token'] ?? null;
        if ($activeLockToken && (! $incomingLockToken || ! hash_equals((string) $activeLockToken, (string) $incomingLockToken))) {
            return response()->json([
                'status' => 'error',
                'rejection' => [
                    'reason' => 'multiple_device_access',
                    'title' => 'Active Session on Another Device',
                    'message' => 'This account already has an active examination session on another device. Multiple-device examination access is not permitted.',
                ],
                'message' => 'This account already has an active examination session on another device. Multiple-device examination access is not permitted.',
            ], 403);
        }

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

    public function submitAttempt(Request $request, string $uuid): JsonResponse
    {
        $this->ensureEnabled();

        $attempt = $this->attemptByUuid($uuid);
        abort_unless($attempt->status === 'in_progress', 422, 'This offline attempt has already been submitted.');

        $incomingLockToken = $request->input('session_lock_token') ?: $request->header('X-CBT-Session-Lock');
        $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
        $activeLockToken = $meta['session_lock_token'] ?? null;
        if ($activeLockToken && (! $incomingLockToken || ! hash_equals((string) $activeLockToken, (string) $incomingLockToken))) {
            return response()->json([
                'status' => 'error',
                'rejection' => [
                    'reason' => 'multiple_device_access',
                    'title' => 'Active Session on Another Device',
                    'message' => 'This account already has an active examination session on another device. Multiple-device examination access is not permitted.',
                ],
                'message' => 'This account already has an active examination session on another device. Multiple-device examination access is not permitted.',
            ], 403);
        }

        $bundle = $this->activeBundle();
        $exam = $bundle ? collect($bundle->payload['exams'] ?? [])->firstWhere('id', (int) $attempt->exam_id) : null;
        $scoreData = $exam ? $this->computeOfflineAttemptScore($attempt, $exam) : ['score' => 0.0, 'total_marks' => 0.0];

        $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
        $metadata['score'] = $scoreData['score'];
        $metadata['total_marks'] = $scoreData['total_marks'] > 0 ? $scoreData['total_marks'] : (float) ($metadata['total_marks'] ?? 0);

        $updateData = [
            'status' => 'submitted',
            'submitted_at' => now(),
            'metadata' => $metadata,
        ];

        if (Schema::hasColumn('offline_cbt_attempts', 'score')) {
            $updateData['score'] = $scoreData['score'];
        }
        if (Schema::hasColumn('offline_cbt_attempts', 'total_marks')) {
            $updateData['total_marks'] = $scoreData['total_marks'] > 0 ? $scoreData['total_marks'] : 0.0;
        }

        $attempt->update($updateData);

        return response()->json([
            'message' => 'Offline CBT exam submitted.',
            'attempt' => $this->attemptPayload($attempt->fresh()),
        ]);
    }

    private function computeOfflineAttemptScore(OfflineCbtAttempt $attempt, array $exam): array
    {
        $questions = collect($exam['questions'] ?? [])->keyBy('id');
        $answers = collect($attempt->answers ?: []);
        $totalMarks = 0.0;
        $totalScore = 0.0;

        foreach ($questions as $qId => $q) {
            if (($q['question_type'] ?? '') === 'theory') {
                continue;
            }
            $marks = (float) ($q['marks'] ?? 1.0);
            $totalMarks += $marks;

            $ans = $answers->firstWhere('question_id', (int) $qId);
            if (! $ans) {
                continue;
            }

            $correctIds = collect($q['options'] ?? [])
                ->where('is_correct', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            $selectedIds = collect($ans['selected_option_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($correctIds !== [] && $correctIds === $selectedIds) {
                $totalScore += $marks;
            }
        }

        return [
            'score' => round($totalScore, 2),
            'total_marks' => round($totalMarks, 2),
        ];
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
            ->map(fn (OfflineCbtAttempt $attempt) => $this->formatAttemptForSync($attempt, $bundle))
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

        $cloudUrl = rtrim(($data['cloud_url'] ?? null) ?: (config('app.url') && !in_array(parse_url(config('app.url'), PHP_URL_HOST), ['localhost', '127.0.0.1']) ? config('app.url') : 'https://gradequestschool.com.ng'), '/');
        $endpoint = $cloudUrl . '/api/cbt/offline/direct-sync/bundle';

        try {
            $response = Http::withoutVerifying()
                ->acceptJson()
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

                // Synchronize active session and term from bundle:
            $activeTermId = (int) ($bundle['active_term_id'] ?? ($bundle['school']['current_term_id'] ?? 0));
            $activeSessionId = (int) ($bundle['active_academic_session_id'] ?? ($bundle['school']['academic_session_id'] ?? 0));
            if ($activeTermId > 0 && Schema::hasTable('terms')) {
                DB::table('terms')->where('school_id', $bundle['school']['id'] ?? 0)->update(['status' => 'Inactive']);
                DB::table('terms')->where('id', $activeTermId)->update(['status' => 'Active']);
            }
            if ($activeSessionId > 0 && Schema::hasTable('academic_sessions')) {
                $hasSessionSchoolId = Schema::hasColumn('academic_sessions', 'school_id');
                $q = DB::table('academic_sessions');
                if ($hasSessionSchoolId) {
                    $q->where('school_id', $bundle['school']['id'] ?? 0);
                }
                $q->update(['is_current' => 0]);
                DB::table('academic_sessions')->where('id', $activeSessionId)->update(['is_current' => 1]);
            }

            // Synchronize administrative resets from bundle:
                foreach ($bundle['exams'] ?? [] as $bExam) {
                    $bExamId = (int) ($bExam['id'] ?? 0);
                    $resetIds = array_map('intval', $bExam['reset_student_ids'] ?? []);
                    if ($bExamId > 0 && !empty($resetIds)) {
                        OfflineCbtAttempt::query()
                            ->where('exam_id', $bExamId)
                            ->whereIn('student_id', $resetIds)
                            ->where('status', '!=', 'cancelled')
                            ->get()
                            ->each(function (OfflineCbtAttempt $att) {
                                $meta = is_array($att->metadata) ? $att->metadata : [];
                                $meta['reset'] = [
                                    'reset_at' => now()->toIso8601String(),
                                    'reset_by' => 'Cloud Portal Sync',
                                    'reason' => 'Administrator reset on GradeQuest cloud portal.',
                                ];
                                $att->update([
                                    'status' => 'cancelled',
                                    'metadata' => $meta,
                                ]);
                            });
                    }
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
            'force_all' => ['nullable', 'boolean'],
        ]);

        $cloudUrl = rtrim(($data['cloud_url'] ?? null) ?: (config('app.url') && !in_array(parse_url(config('app.url'), PHP_URL_HOST), ['localhost', '127.0.0.1']) ? config('app.url') : 'https://gradequestschool.com.ng'), '/');
        $endpoint = $cloudUrl . '/api/cbt/offline/direct-sync/results';

        $examIds = collect($bundle->payload['exams'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        $attemptsQuery = OfflineCbtAttempt::query()
            ->where('status', 'submitted')
            ->where(function ($q) use ($bundle, $examIds) {
                $q->where('offline_cbt_bundle_id', $bundle->id);
                if (!empty($examIds)) {
                    $q->orWhereIn('exam_id', $examIds);
                }
            });

        // Only select un-synced attempts unless forced
        if (! $request->boolean('force_all')) {
            if (Schema::hasColumn('offline_cbt_attempts', 'is_synced')) {
                $attemptsQuery->where(function ($q) {
                    $q->whereNull('is_synced')->orWhere('is_synced', false);
                });
            }
        }

        $attempts = $attemptsQuery
            ->orderBy('submitted_at')
            ->get()
            ->unique('offline_attempt_uuid')
            ->values();

        if ($attempts->isEmpty()) {
            return response()->json(['message' => 'No un-synchronized submitted student attempts found to push.'], 422);
        }

        $payloadAttempts = $attempts->map(fn (OfflineCbtAttempt $attempt) => $this->formatAttemptForSync($attempt, $bundle))->values()->all();

        $syncReference = 'offline-cloud-push-' . ($bundle->school_id ?: 'school') . '-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(5));

        try {
            $response = Http::withoutVerifying()
                ->acceptJson()
                ->timeout(120)
                ->post($endpoint, [
                    'license_key' => trim($licenseKey),
                    'sync_reference' => $syncReference,
                    'attempts' => $payloadAttempts,
                    'admin_email' => $data['admin_email'] ?? null,
                    'admin_password' => $data['admin_password'] ?? null,
                ]);

            if ($response->failed()) {
                $err = $response->json('message') ?: ('Cloud sync failed with status ' . $response->status());
                $inconsistencies = $response->json('inconsistencies') ?? [];
                return response()->json([
                    'message' => $err,
                    'inconsistencies' => $inconsistencies,
                ], 422);
            }

            $syncedUuids = $response->json('synced_uuids') ?? [];
            if (empty($syncedUuids)) {
                $syncedUuids = $attempts->pluck('offline_attempt_uuid')->all();
            }

            if (Schema::hasColumn('offline_cbt_attempts', 'is_synced')) {
                OfflineCbtAttempt::whereIn('offline_attempt_uuid', $syncedUuids)->update([
                    'is_synced' => true,
                    'synced_at' => now(),
                ]);
            }

            $inconsistencies = $response->json('inconsistencies') ?? [];
            if (!empty($inconsistencies)) {
                foreach ($inconsistencies as $inc) {
                    if (!empty($inc['attempt_uuid'])) {
                        $att = OfflineCbtAttempt::where('offline_attempt_uuid', $inc['attempt_uuid'])->first();
                        if ($att) {
                            $meta = is_array($att->metadata) ? $att->metadata : [];
                            $meta['last_sync_inconsistency'] = $inc;
                            $att->update(['metadata' => $meta]);
                        }
                    }
                }
            }

            $summary = $response->json('summary') ?? [];
            $msg = 'Successfully pushed ' . count($syncedUuids) . ' student result(s) directly to GradeQuest Cloud! Scores recorded in report cards.';
            if (!empty($inconsistencies)) {
                $msg .= ' (' . count($inconsistencies) . ' attempt(s) flagged with question inconsistencies).';
            }

            return response()->json([
                'message' => $msg,
                'summary' => $summary,
                'synced_uuids' => $syncedUuids,
                'inconsistencies' => $inconsistencies,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not connect to Cloud server: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Format and map an attempt's answers to the active bundle/cloud question structure
     */
    private function formatAttemptForSync(OfflineCbtAttempt $attempt, OfflineCbtBundle $activeBundle): array
    {
        $activeExam = collect($activeBundle->payload['exams'] ?? [])->firstWhere('id', (int) $attempt->exam_id);
        $attemptBundle = $attempt->bundle ?: $activeBundle;
        $attemptExam = collect($attemptBundle->payload['exams'] ?? [])->firstWhere('id', (int) $attempt->exam_id) ?: $activeExam;

        $mappedAnswers = [];
        foreach ($attempt->answers ?: [] as $ans) {
            $rawQId = (int) ($ans['question_id'] ?? 0);
            $originQ = $attemptExam ? collect($attemptExam['questions'] ?? [])->firstWhere('id', $rawQId) : null;

            $targetQ = null;
            if ($activeExam) {
                // 1. Direct ID match in active bundle
                $targetQ = collect($activeExam['questions'] ?? [])->firstWhere('id', $rawQId);

                // 2. Question text match (preserves answer mapping if questions were regenerated in cloud after package generation)
                if (! $targetQ && $originQ && ! empty($originQ['question_text'])) {
                    $cleanText = trim(mb_strtolower((string) $originQ['question_text']));
                    $targetQ = collect($activeExam['questions'] ?? [])->first(fn ($q) => trim(mb_strtolower($q['question_text'] ?? '')) === $cleanText);
                }

                // 3. Sort order match if question count and types correspond
                if (! $targetQ && $originQ && (count($activeExam['questions'] ?? []) === count($attemptExam['questions'] ?? []))) {
                    $candidate = collect($activeExam['questions'] ?? [])->firstWhere('sort_order', $originQ['sort_order'] ?? null);
                    if ($candidate && ($candidate['question_type'] ?? '') === ($originQ['question_type'] ?? '')) {
                        $targetQ = $candidate;
                    }
                }
            }

            $effectiveQ = $targetQ ?: $originQ;
            $mappedQId = $targetQ ? (int) $targetQ['id'] : $rawQId;

            // Safe option re-mapping if question was remapped
            $mappedOptionIds = $ans['selected_option_ids'] ?? [];
            if ($targetQ && $originQ && (int) $targetQ['id'] !== (int) $originQ['id']) {
                $mappedOptionIds = [];
                foreach ($ans['selected_option_ids'] ?? [] as $origOptId) {
                    $origOpt = collect($originQ['options'] ?? [])->firstWhere('id', (int) $origOptId);
                    if ($origOpt) {
                        $matchedOpt = collect($targetQ['options'] ?? [])->first(fn ($o) =>
                            (!empty($origOpt['label']) && $o['label'] === $origOpt['label']) ||
                            (!empty($origOpt['sort_order']) && $o['sort_order'] === $origOpt['sort_order']) ||
                            (trim($o['option_text'] ?? '') === trim($origOpt['option_text'] ?? ''))
                        );
                        if ($matchedOpt) {
                            $mappedOptionIds[] = (int) $matchedOpt['id'];
                        }
                    }
                }
            }

            $mappedAnswers[] = [
                'question_id' => $mappedQId,
                'cloud_question_id' => $mappedQId,
                'original_question_id' => $rawQId,
                'sort_order' => (int) ($effectiveQ['sort_order'] ?? 1),
                'question_type' => $effectiveQ['question_type'] ?? 'single_choice',
                'question_text' => $effectiveQ['question_text'] ?? null,
                'selected_option_ids' => $mappedOptionIds,
                'answer_text' => $ans['answer_text'] ?? null,
            ];
        }

        return [
            'offline_attempt_uuid' => $attempt->offline_attempt_uuid,
            'exam_id' => (int) $attempt->exam_id,
            'student_id' => (int) $attempt->student_id,
            'started_at' => optional($attempt->started_at)->toIso8601String(),
            'submitted_at' => optional($attempt->submitted_at)->toIso8601String(),
            'device_name' => $attempt->device_name,
            'ip_address' => $attempt->ip_address,
            'events_count' => (int) $attempt->events_count,
            'answers' => $mappedAnswers,
        ];
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

    public function detectNetworkInterfaces(): array
    {
        $ips = collect(gethostbynamel(gethostname()) ?: []);

        // On Windows, if offline or on a hotspot without active external DNS, gethostbynamel() often fails.
        // Fallback to ipconfig to parse all active network adapter IPv4 addresses reliably:
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            @exec('ipconfig 2>&1', $output);
            foreach ($output as $line) {
                if (preg_match('/IPv4 Address[ .:]+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/i', $line, $matches)) {
                    $ips->push(trim($matches[1]));
                }
            }
        } else {
            $output = [];
            @exec("hostname -I 2>/dev/null", $output);
            if (!empty($output[0])) {
                foreach (explode(' ', trim($output[0])) as $candidate) {
                    $ips->push(trim($candidate));
                }
            }
        }

        return $ips
            ->merge([$_SERVER['SERVER_ADDR'] ?? null])
            ->filter()
            ->map(fn ($ip) => trim((string) $ip))
            ->filter(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            ->reject(fn ($ip) => str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.'))
            ->unique()
            ->values()
            ->all();
    }

    public function detectInternetConnectivity(): array
    {
        $hasInternet = false;
        $latencyMs = null;
        $checkedEndpoint = null;
        $start = microtime(true);

        $probes = [
            ['host' => '1.1.1.1', 'port' => 53],
            ['host' => '8.8.8.8', 'port' => 53],
            ['host' => 'gradequestschool.com.ng', 'port' => 443],
        ];

        foreach ($probes as $probe) {
            $socket = @fsockopen($probe['host'], $probe['port'], $errno, $errstr, 1.2);
            if ($socket) {
                $hasInternet = true;
                $latencyMs = (int) round((microtime(true) - $start) * 1000);
                $checkedEndpoint = "{$probe['host']}:{$probe['port']}";
                @fclose($socket);
                break;
            }
        }

        $dnsWorking = false;
        if ($hasInternet) {
            $dnsTest = @gethostbyname('dns.google');
            $dnsWorking = ($dnsTest !== 'dns.google');
        }

        return [
            'has_internet' => $hasInternet,
            'dns_working' => $dnsWorking,
            'latency_ms' => $latencyMs,
            'checked_endpoint' => $checkedEndpoint,
            'mode' => $hasInternet ? 'online' : 'offline_mode',
            'explanation' => $hasInternet
                ? 'External internet connectivity is active. Cloud sync is available.'
                : 'Computer is operating in Offline CBT Mode. External internet is unavailable, but local examinations, timers, and candidate access are fully functional.',
        ];
    }

    public function networkCheck(): JsonResponse
    {
        $this->ensureEnabled();
        $ips = $this->detectNetworkInterfaces();
        $internet = $this->detectInternetConnectivity();

        return response()->json([
            'status' => 'ok',
            'local_network' => [
                'available' => !empty($ips),
                'ips' => $ips,
                'student_urls' => array_map(fn ($ip) => "http://{$ip}:8088/cbt/student", $ips),
            ],
            'internet' => $internet,
        ]);
    }

    private function serverInfo(string $requestHost): array
    {
        $ips = $this->detectNetworkInterfaces();
        $internet = $this->detectInternetConnectivity();

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
            'network_status' => [
                'has_local_network' => !empty($ips),
                'local_ips_count' => count($ips),
                'has_internet' => $internet['has_internet'],
                'dns_working' => $internet['dns_working'],
                'mode' => $internet['mode'],
                'explanation' => $internet['explanation'],
            ],
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
        $cloudResetStudentIds = array_map('intval', $exam['reset_student_ids'] ?? []);

        // If candidate was reset in cloud, cancel any local submitted attempt so retake is unblocked
        if (in_array($studentId, $cloudResetStudentIds, true)) {
            OfflineCbtAttempt::query()
                ->where('exam_id', $examId)
                ->where('student_id', $studentId)
                ->where('status', 'submitted')
                ->get()
                ->each(function (OfflineCbtAttempt $att) {
                    $meta = is_array($att->metadata) ? $att->metadata : [];
                    $meta['reset'] = [
                        'reset_at' => now()->toIso8601String(),
                        'reset_by' => 'Cloud Portal Sync',
                        'reason' => 'Synchronized attempt reset from SchoolProfit Cloud.',
                    ];
                    $att->update(['status' => 'cancelled', 'metadata' => $meta]);
                });
        }

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
            'term' => is_array($exam['term'] ?? null) ? ($exam['term']['name'] ?? null) : ($exam['term'] ?? null),
            'academic_session' => is_array($exam['academic_session'] ?? null) ? ($exam['academic_session']['name'] ?? null) : ($exam['academic_session'] ?? null),
            'schedules' => $exam['schedules'] ?? [],
            'is_open' => $this->examIsOpen($exam),
            'is_upcoming' => $this->examIsUpcoming($exam),
            'is_expired' => $this->examIsExpired($exam),
            'schedule_summary' => $this->scheduleSummary($exam),
            'submitted' => $submitted,
        ];
    }

    private function examPaperPayload(array $exam): array
    {
        $allQuestions = collect($exam['questions'] ?? [])
            ->sortBy(fn ($question) => sprintf('%08d_%08d', (int) ($question['sort_order'] ?? 0), (int) ($question['id'] ?? 0)))
            ->values();

        $objectiveQuestions = $allQuestions
            ->filter(fn ($q) => ($q['question_type'] ?? 'single_choice') !== 'theory')
            ->values()
            ->all();

        if ($exam['shuffle_questions'] ?? false) {
            $objectiveQuestions = collect($objectiveQuestions)->shuffle()->values()->all();
        }

        // Theory questions are NEVER shuffled under any condition. Sequence is strictly preserved.
        $theoryQuestions = $allQuestions
            ->filter(fn ($q) => ($q['question_type'] ?? '') === 'theory')
            ->sortBy(fn ($q) => sprintf('%08d_%08d', (int) ($q['sort_order'] ?? 0), (int) ($q['id'] ?? 0)))
            ->values()
            ->map(fn ($q, int $idx) => [
                'id' => (int) ($q['id'] ?? 0),
                'number' => $idx + 1,
                'question_type' => 'theory',
                'question_text' => $q['question_text'] ?? '',
                'instructions' => $q['instructions'] ?? null,
                'marks' => (float) ($q['marks'] ?? 0),
                'diagram_url' => $q['diagram_url'] ?? null,
                'sort_order' => (int) ($q['sort_order'] ?? 0),
                'sub_questions' => $q['sub_questions'] ?? ($q['metadata']['sub_questions'] ?? []),
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
        $durationMinutes = 60;
        $bundle = $this->activeBundle();
        if ($bundle) {
            $exam = collect($bundle->payload['exams'] ?? [])->first(fn ($item) => (int) ($item['id'] ?? 0) === (int) $attempt->exam_id);
            if ($exam && !empty($exam['duration_minutes'])) {
                $durationMinutes = (int) $exam['duration_minutes'];
            }
        }

        $startedAt = $attempt->started_at;
        $remainingSeconds = $durationMinutes * 60;
        if ($startedAt) {
            $elapsed = (int) $startedAt->diffInSeconds(now());
            $remainingSeconds = max(0, ($durationMinutes * 60) - $elapsed);
        }

        $meta = is_array($attempt->metadata) ? $attempt->metadata : [];

        return [
            'id' => $attempt->id,
            'offline_attempt_uuid' => $attempt->offline_attempt_uuid,
            'session_lock_token' => $meta['session_lock_token'] ?? null,
            'status' => $attempt->status,
            'answers' => $attempt->answers ?: [],
            'started_at' => optional($attempt->started_at)->toIso8601String(),
            'submitted_at' => optional($attempt->submitted_at)->toIso8601String(),
            'duration_minutes' => $durationMinutes,
            'remaining_seconds' => $remainingSeconds,
            'server_time' => now()->toIso8601String(),
        ];
    }

    public function listAttempts(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $bundle = $this->activeBundle();
        $limit = min(300, max(10, (int) $request->input('limit', 150)));

        $dbAttempts = OfflineCbtAttempt::query()
            ->when($bundle?->school_id, fn ($q) => $q->where('school_id', $bundle->school_id))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $attemptedPairs = [];

        $mappedAttempts = $dbAttempts->map(function (OfflineCbtAttempt $attempt) use ($bundle, &$attemptedPairs) {
            $attemptedPairs[$attempt->exam_id . '_' . $attempt->student_id] = true;

            $exam = $bundle ? collect($bundle->payload['exams'] ?? [])->first(fn ($item) => (int) ($item['id'] ?? 0) === (int) $attempt->exam_id) : null;
            $student = $bundle ? collect($bundle->payload['students'] ?? [])->first(fn ($item) => (int) ($item['id'] ?? 0) === (int) $attempt->student_id) : null;

            $answeredCount = collect($attempt->answers ?? [])
                ->filter(fn ($a) => !empty($a['selected_option_ids']) || trim((string)($a['answer_text'] ?? '')) !== '')
                ->count();

            $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
            $events = $meta['events'] ?? [];
            $hasCrashErrors = collect($events)->contains(fn ($e) =>
                in_array($e['severity'] ?? '', ['high', 'critical']) ||
                str_contains(strtolower($e['event_type'] ?? ''), 'error') ||
                str_contains(strtolower($e['event_type'] ?? ''), 'crash')
            );

            // Technical fault suspected: accidental premature submission with 0 answers or crash events logged
            $technicalFaultSuspected = ($attempt->status === 'submitted' && $answeredCount === 0) || ($attempt->status === 'submitted' && $hasCrashErrors);

            $candidateName = trim($student['name'] ?? trim(($student['firstname'] ?? '') . ' ' . ($student['surname'] ?? '')));
            if ($candidateName === '') {
                $candidateName = $attempt->student_reg_no ? "Candidate ({$attempt->student_reg_no})" : "Candidate #{$attempt->student_id}";
            }

            $subjectName = $exam['subject']['name'] ?? ($exam['subject'] ?? ($exam['title'] ?? 'N/A'));

            $statusKey = $attempt->status;
            $statusLabel = 'In Progress';
            if ($statusKey === 'submitted') {
                $statusLabel = 'Submitted';
            } elseif ($statusKey === 'cancelled') {
                $statusKey = 'exceptionally_reset';
                $statusLabel = 'Exceptionally Reset';
            } elseif ($statusKey === 'in_progress') {
                $statusLabel = 'In Progress';
            }

            $scoreVal = $attempt->score !== null ? (float) $attempt->score : ($meta['score'] ?? null);
            $totalMarksVal = $attempt->total_marks !== null ? (float) $attempt->total_marks : ($meta['total_marks'] ?? null);

            $started = $attempt->started_at;
            $ended = $attempt->submitted_at ?? now();
            $durationSec = $started ? (int) $started->diffInSeconds($ended) : 0;

            return [
                'id' => $attempt->id,
                'offline_attempt_uuid' => $attempt->offline_attempt_uuid,
                'exam_id' => $attempt->exam_id,
                'exam_title' => $exam['title'] ?? ('Exam #' . $attempt->exam_id),
                'subject' => $subjectName,
                'student_id' => $attempt->student_id,
                'student_name' => $candidateName,
                'student_reg_no' => $attempt->student_reg_no ?: ($student['reg_no'] ?? null),
                'class_name' => $student['class'] ?? ($exam['class']['name'] ?? ($exam['class'] ?? null)),
                'status' => $statusKey,
                'raw_status' => $attempt->status,
                'status_label' => $statusLabel,
                'score' => $statusKey === 'submitted' || $statusKey === 'exceptionally_reset' ? $scoreVal : null,
                'total_marks' => $totalMarksVal,
                'started_at' => optional($attempt->started_at)->toIso8601String(),
                'submitted_at' => optional($attempt->submitted_at)->toIso8601String(),
                'duration_seconds' => $durationSec,
                'duration_formatted' => sprintf('%02d:%02d', floor($durationSec / 60), $durationSec % 60),
                'answered_count' => $answeredCount,
                'total_questions' => count($exam['questions'] ?? []),
                'device_name' => $attempt->device_name,
                'ip_address' => $attempt->ip_address,
                'technical_fault_suspected' => $technicalFaultSuspected,
                'device_transfer_authorized' => !empty($meta['device_transfer_authorized']),
                'metadata' => $meta,
                'events' => $events,
                'technical_recovery' => $meta['technical_recovery'] ?? null,
            ];
        });

        // Unstarted candidates from active bundle
        $unstartedCandidates = [];
        if ($bundle && !empty($bundle->payload['exams'])) {
            foreach ($bundle->payload['exams'] as $bExam) {
                $eId = (int) ($bExam['id'] ?? 0);
                $subjName = $bExam['subject']['name'] ?? ($bExam['subject'] ?? ($bExam['title'] ?? 'N/A'));
                foreach ($bExam['eligible_student_ids'] ?? [] as $sId) {
                    $sId = (int) $sId;
                    if (!isset($attemptedPairs[$eId . '_' . $sId])) {
                        $studentObj = collect($bundle->payload['students'] ?? [])->firstWhere('id', $sId);
                        $candName = trim($studentObj['name'] ?? trim(($studentObj['firstname'] ?? '') . ' ' . ($studentObj['surname'] ?? '')));
                        if ($candName === '') {
                            $candName = !empty($studentObj['reg_no']) ? "Candidate ({$studentObj['reg_no']})" : "Candidate #{$sId}";
                        }
                        $unstartedCandidates[] = [
                            'id' => null,
                            'offline_attempt_uuid' => 'unstarted_' . $eId . '_' . $sId,
                            'exam_id' => $eId,
                            'exam_title' => $bExam['title'] ?? 'Exam',
                            'subject' => $subjName,
                            'student_id' => $sId,
                            'student_name' => $candName,
                            'student_reg_no' => $studentObj['reg_no'] ?? null,
                            'class_name' => $studentObj['class'] ?? ($bExam['class']['name'] ?? ($bExam['class'] ?? null)),
                            'status' => 'not_started',
                            'raw_status' => 'not_started',
                            'status_label' => 'Not Started',
                            'score' => null,
                            'total_marks' => null,
                            'started_at' => null,
                            'submitted_at' => null,
                            'duration_seconds' => 0,
                            'duration_formatted' => '—',
                            'answered_count' => 0,
                            'total_questions' => count($bExam['questions'] ?? []),
                            'device_name' => null,
                            'ip_address' => null,
                            'technical_fault_suspected' => false,
                            'device_transfer_authorized' => false,
                            'metadata' => null,
                            'events' => [],
                            'technical_recovery' => null,
                        ];
                    }
                }
            }
        }

        $allRows = $mappedAttempts->concat($unstartedCandidates)->values();

        return response()->json([
            'attempts' => $allRows,
        ]);
    }

    /**
     * Inspect attempt state and evidence for technical fault review
     */
    public function inspectFault(string $uuid): JsonResponse
    {
        $this->ensureEnabled();
        $attempt = $this->attemptByUuid($uuid);
        $bundle = $this->activeBundle();
        $exam = $bundle ? collect($bundle->payload['exams'] ?? [])->firstWhere('id', (int) $attempt->exam_id) : null;
        $student = $bundle ? collect($bundle->payload['students'] ?? [])->firstWhere('id', (int) $attempt->student_id) : null;

        $answeredCount = collect($attempt->answers ?? [])
            ->filter(fn ($a) => !empty($a['selected_option_ids']) || trim((string)($a['answer_text'] ?? '')) !== '')
            ->count();

        $started = $attempt->started_at;
        $ended = $attempt->submitted_at ?? now();
        $durationSeconds = $started ? (int) $started->diffInSeconds($ended) : 0;

        $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
        $events = $meta['events'] ?? [];

        $errorEvents = collect($events)->filter(fn ($e) =>
            str_contains(strtolower($e['event_type'] ?? ''), 'crash') ||
            str_contains(strtolower($e['event_type'] ?? ''), 'system_error') ||
            str_contains(strtolower($e['event_type'] ?? ''), 'runtime_error') ||
            str_contains(strtolower($e['event_type'] ?? ''), 'hardware_fault')
        )->values()->all();

        $candidateName = trim($student['name'] ?? trim(($student['firstname'] ?? '') . ' ' . ($student['surname'] ?? '')));
        if ($candidateName === '') {
            $candidateName = $attempt->student_reg_no ? "Candidate ({$attempt->student_reg_no})" : "Candidate #{$attempt->student_id}";
        }

        $isZeroAnswers = ($answeredCount === 0);
        $isShortDuration = ($durationSeconds < 180);
        $hasErrors = !empty($errorEvents);

        $verified = false;
        $verdictMessage = '';

        if ($isZeroAnswers) {
            $verified = true;
            $verdictMessage = 'Verified Technical Fault: Candidate recorded 0 answered questions before the examination session terminated. Verified accidental submission/dropout.';
        } elseif ($hasErrors && $isShortDuration) {
            $verified = true;
            $verdictMessage = 'Verified Technical Fault: System error logged and session terminated abnormally early (' . round($durationSeconds / 60, 1) . ' mins).';
        } else {
            $verified = false;
            $verdictMessage = "Technical Fault NOT Verified: Attempt indicates normal examination participation with {$answeredCount} answered question(s) (Duration: " . round($durationSeconds / 60, 1) . " mins). Under the strict one-attempt policy, examination resets are strictly prohibited.";
        }

        $scoreVal = $attempt->score !== null ? (float) $attempt->score : ($meta['score'] ?? null);
        $totalMarksVal = $attempt->total_marks !== null ? (float) $attempt->total_marks : ($meta['total_marks'] ?? null);

        return response()->json([
            'attempt_uuid' => $attempt->offline_attempt_uuid,
            'student_id' => $attempt->student_id,
            'student_name' => $candidateName,
            'student_reg_no' => $attempt->student_reg_no ?: ($student['reg_no'] ?? null),
            'exam_id' => $attempt->exam_id,
            'exam_title' => $exam['title'] ?? ('Exam #' . $attempt->exam_id),
            'subject' => $exam['subject']['name'] ?? ($exam['subject'] ?? ($exam['title'] ?? 'N/A')),
            'status' => $attempt->status,
            'score' => $scoreVal,
            'total_marks' => $totalMarksVal,
            'answered_count' => $answeredCount,
            'total_questions' => count($exam['questions'] ?? []),
            'duration_seconds' => $durationSeconds,
            'duration_formatted' => sprintf('%02d:%02d', floor($durationSeconds / 60), $durationSeconds % 60),
            'device_name' => $attempt->device_name,
            'ip_address' => $attempt->ip_address,
            'events' => $events,
            'error_events' => $errorEvents,
            'verified' => $verified,
            'verdict_message' => $verdictMessage,
        ]);
    }

    /**
     * Authorize exceptional technical-fault recovery
     */
    public function authorizeTechnicalRecovery(Request $request, string $uuid): JsonResponse
    {
        $this->ensureEnabled();
        $attempt = $this->attemptByUuid($uuid);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'admin_name' => ['nullable', 'string', 'max:120'],
        ]);

        $answeredCount = collect($attempt->answers ?? [])
            ->filter(fn ($a) => !empty($a['selected_option_ids']) || trim((string)($a['answer_text'] ?? '')) !== '')
            ->count();

        $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
        $events = $meta['events'] ?? [];
        $technicalFaultEvents = collect($events)->filter(fn ($e) =>
            str_contains(strtolower($e['event_type'] ?? ''), 'crash') ||
            str_contains(strtolower($e['event_type'] ?? ''), 'system_error') ||
            str_contains(strtolower($e['event_type'] ?? ''), 'runtime_error') ||
            str_contains(strtolower($e['event_type'] ?? ''), 'hardware_fault')
        );
        $hasCrashErrors = $technicalFaultEvents->isNotEmpty();

        if ($answeredCount > 0 && !$hasCrashErrors) {
            return response()->json([
                'status' => 'error',
                'message' => 'Exceptional recovery denied: Attempt inspection indicates normal examination activity with ' . $answeredCount . ' answered question(s). Under the strict one-attempt policy, resets are strictly prohibited.',
            ], 422);
        }

        $adminName = !empty($data['admin_name']) ? $data['admin_name'] : 'Offline CBT Administrator';

        // Preserve original attempt with full audit trail (NEVER delete)
        $meta['technical_recovery'] = [
            'authorized_at' => now()->toIso8601String(),
            'authorized_by' => $adminName,
            'reason' => $data['reason'],
            'original_score' => $attempt->score ?? ($meta['score'] ?? null),
            'original_status' => $attempt->status,
            'original_submitted_at' => optional($attempt->submitted_at)->toIso8601String(),
            'answered_count' => $answeredCount,
        ];

        $meta['events'] = $events;
        $meta['events'][] = [
            'event_type' => 'exceptional_recovery_authorized',
            'severity' => 'high',
            'metadata' => [
                'authorized_by' => $adminName,
                'reason' => $data['reason'],
            ],
            'occurred_at' => now()->toIso8601String(),
        ];

        $attempt->update([
            'status' => 'cancelled',
            'metadata' => $meta,
        ]);

        return response()->json([
            'message' => 'Exceptional recovery authorized. Original attempt preserved in audit log. The candidate may now retake the examination.',
            'attempt' => $this->attemptPayload($attempt->fresh()),
        ]);
    }

    /**
     * Authorize device transfer for an in-progress attempt
     */
    public function authorizeDeviceTransfer(Request $request, string $uuid): JsonResponse
    {
        $this->ensureEnabled();
        $attempt = $this->attemptByUuid($uuid);
        abort_unless($attempt->status === 'in_progress', 422, 'Device transfer can only be authorized for in-progress examination sessions.');

        $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
        $meta['device_transfer_authorized'] = true;
        $meta['device_transfer_authorized_at'] = now()->toIso8601String();
        $meta['events'] = $meta['events'] ?? [];
        $meta['events'][] = [
            'event_type' => 'device_transfer_authorized_by_admin',
            'severity' => 'medium',
            'metadata' => [
                'authorized_at' => now()->toIso8601String(),
                'authorized_by' => $request->input('admin_name', 'Offline CBT Administrator'),
            ],
            'occurred_at' => now()->toIso8601String(),
        ];

        $attempt->update(['metadata' => $meta]);

        return response()->json([
            'message' => 'Device transfer authorized. The candidate can now log in on a new device to resume this examination.',
        ]);
    }

    public function resetAttempt(Request $request, string $uuid): JsonResponse
    {
        // Route legacy reset attempts through strict technical recovery validation
        return $this->authorizeTechnicalRecovery($request, $uuid);
    }

    public function resetStudentExam(Request $request, int $examId, int $studentId): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
            'admin_name' => ['nullable', 'string', 'max:120'],
        ]);

        $attempts = OfflineCbtAttempt::query()
            ->where('exam_id', $examId)
            ->where('student_id', $studentId)
            ->where('status', '!=', 'cancelled')
            ->get();

        foreach ($attempts as $attempt) {
            $answeredCount = collect($attempt->answers ?? [])
                ->filter(fn ($a) => !empty($a['selected_option_ids']) || trim((string)($a['answer_text'] ?? '')) !== '')
                ->count();

            if ($answeredCount > 0) {
                return response()->json([
                    'message' => 'Reset prohibited: Student attempt has ' . $answeredCount . ' answered questions. Under the strict one-attempt policy, resets are strictly prohibited.',
                ], 422);
            }

            $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
            $metadata['technical_recovery'] = [
                'authorized_at' => now()->toIso8601String(),
                'authorized_by' => $data['admin_name'] ?? 'Offline CBT Administrator',
                'reason' => $data['reason'],
                'original_score' => $attempt->score ?? ($metadata['score'] ?? null),
            ];
            $attempt->update([
                'status' => 'cancelled',
                'metadata' => $metadata,
            ]);
        }

        return response()->json([
            'message' => 'Candidate exam attempt exceptionally reset under technical fault policy.',
            'count' => $attempts->count(),
        ]);
    }

    private function examIsOpen(array $exam): bool
    {
        $schedules = $exam['schedules'] ?? [];
        if (! is_array($schedules) || $schedules === []) {
            return true;
        }

        $tz = 'Africa/Lagos';
        $nowLocal = now($tz);

        return collect($schedules)->contains(function ($schedule) use ($nowLocal, $tz) {
            $rawDate = trim((string) ($schedule['exam_date'] ?? ''));
            if ($rawDate === '') {
                return false;
            }
            $examDate = substr($rawDate, 0, 10);
            $startsAt = $this->normalizeTime($schedule['starts_at'] ?? null, '00:00:00');
            $endsAt = $this->normalizeTime($schedule['ends_at'] ?? null, '23:59:59');

            try {
                $startDt = Carbon::parse("{$examDate} {$startsAt}", $tz);
                $endDt = Carbon::parse("{$examDate} {$endsAt}", $tz);

                return $nowLocal->greaterThanOrEqualTo($startDt) && $nowLocal->lessThanOrEqualTo($endDt);
            } catch (\Throwable $e) {
                return false;
            }
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

    private function examIsUpcoming(array $exam): bool
    {
        $schedules = $exam['schedules'] ?? [];
        if (! is_array($schedules) || $schedules === []) {
            return false;
        }

        if ($this->examIsOpen($exam)) {
            return false;
        }

        $tz = 'Africa/Lagos';
        $nowLocal = now($tz);

        return collect($schedules)->contains(function ($schedule) use ($nowLocal, $tz) {
            $rawDate = trim((string) ($schedule['exam_date'] ?? ''));
            if ($rawDate === '') {
                return false;
            }
            $examDate = substr($rawDate, 0, 10);
            $startsAt = $this->normalizeTime($schedule['starts_at'] ?? null, '00:00:00');

            try {
                $startDt = Carbon::parse("{$examDate} {$startsAt}", $tz);

                return $nowLocal->lessThan($startDt);
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    private function examIsExpired(array $exam): bool
    {
        $schedules = $exam['schedules'] ?? [];
        if (! is_array($schedules) || $schedules === []) {
            return false;
        }

        return ! $this->examIsOpen($exam) && ! $this->examIsUpcoming($exam);
    }

    private function scheduleSummary(array $exam): ?array
    {
        $schedules = $exam['schedules'] ?? [];
        if (! is_array($schedules) || $schedules === []) {
            return null;
        }

        $first = collect($schedules)->first();
        if (!$first) {
            return null;
        }

        $rawDate = trim((string) ($first['exam_date'] ?? ''));
        $examDate = substr($rawDate, 0, 10);

        return [
            'exam_date' => $examDate ?: null,
            'starts_at' => $first['starts_at'] ?? null,
            'ends_at' => $first['ends_at'] ?? null,
            'venue' => $first['venue'] ?? null,
            'timezone' => 'Africa/Lagos',
        ];
    }

    public function heartbeatStudent(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'student_reg_no' => ['required', 'string'],
            'session_token' => ['nullable', 'string'],
        ]);

        $token = trim((string) ($request->header('X-Student-Session-Token') ?: ($data['session_token'] ?? null) ?: ''));
        if (empty($token) || !Schema::hasTable('cbt_student_sessions')) {
            return response()->json(['status' => 'ok']);
        }

        $session = CbtStudentSession::where('student_reg_no', trim($data['student_reg_no']))
            ->where('session_token', $token)
            ->where('is_active', true)
            ->first();

        if (!$session) {
            return response()->json([
                'status' => 'expired',
                'message' => 'Your student session has expired or was released. Please log in again.',
            ], 401);
        }

        $session->update([
            'last_activity_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['status' => 'ok', 'last_activity_at' => now()->toIso8601String()]);
    }

    public function logoutStudent(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'student_reg_no' => ['required', 'string'],
            'session_token' => ['nullable', 'string'],
        ]);

        $token = trim((string) ($request->header('X-Student-Session-Token') ?: ($data['session_token'] ?? null) ?: ''));
        if (Schema::hasTable('cbt_student_sessions')) {
            $query = CbtStudentSession::where('student_reg_no', trim($data['student_reg_no']))
                ->where('is_active', true);
            if ($token !== '') {
                $query->where('session_token', $token);
            }
            $query->update([
                'is_active' => false,
                'released_at' => now(),
                'release_reason' => 'logged_out',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully. This computer has released the session.',
        ]);
    }

    public function releaseStudentSession(Request $request, int $studentId): JsonResponse
    {
        $this->ensureEnabled();

        $reason = $request->input('reason', 'admin_override');
        $adminIdentifier = $request->input('released_by', 'admin_console');

        if (Schema::hasTable('cbt_student_sessions')) {
            CbtStudentSession::where('student_id', $studentId)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'released_at' => now(),
                    'release_reason' => $reason,
                    'released_by' => $adminIdentifier,
                ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Student session released successfully. The student may now log in on another computer.',
        ]);
    }

    public function listActiveSessions(Request $request): JsonResponse
    {
        $this->ensureEnabled();

        if (!Schema::hasTable('cbt_student_sessions')) {
            return response()->json(['sessions' => []]);
        }

        $sessions = CbtStudentSession::where('is_active', true)
            ->orderByDesc('last_activity_at')
            ->limit(100)
            ->get();

        return response()->json(['sessions' => $sessions]);
    }
}
