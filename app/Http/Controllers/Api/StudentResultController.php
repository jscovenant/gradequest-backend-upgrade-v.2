<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Http\Requests\Result\UpsertStudentResultRequest;
use App\Http\Requests\Result\GetReportCardRequest;
use App\Models\AcademicSession;
use App\Models\SchoolSetting;
use App\Models\ResultTemplateSetting;
use App\Models\ResultBatch;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Repositories\ResultRepository;
use App\Services\SchoolBillingService;
use App\Services\SchoolFeeAccessPolicyService;
use App\Services\Results\SubjectService;
use App\Services\Results\ResultComputeService;
use App\Services\Results\AiResultCommentGeneratorService;
use App\Services\ResultService;
use App\Services\SubscriptionGate;
use App\Services\SubscriptionAiCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use App\Models\TeacherEnrollment;
use App\Jobs\UpdateResultSubmissionMonitorJob;
use App\Jobs\ScanBatchResultAnomaliesJob;

class StudentResultController extends Controller
{
  /**
   * Inject dependencies:
   * - ResultRepository: reusable DB queries for results (v2 + legacy)
   * - SubjectService: business rule for picking subjects (department-based)
   */
  public function __construct(
    private ResultRepository $repo,
    private SubjectService $subjectService,
    private ResultComputeService $computeService,
    private SchoolBillingService $schoolBillingService,
    private SchoolFeeAccessPolicyService $feeAccessPolicyService
  ) {}

  /**
   * Find a student by admission number (reg_no) within the authenticated user's school,
   * then return context needed to start result entry (term/session/subjects).
   */



public function findByAdmissionNo($admissionNo)
{
    $auth = Auth::user();

    // For Teacher: fetch assigned class IDs
    $allowedLevelIds = collect();
    if ($auth->role === 'Teacher') {
        $allowedLevelIds = TeacherEnrollment::query()
            ->where('user_id', $auth->id)
            ->where('school_id', $auth->school_id)
            ->where('enroll', '1')
            ->pluck('level_id');

        if ($allowedLevelIds->isEmpty()) {
            return response()->json([
                'message' => 'No class assigned to this teacher. Please contact Admin.',
            ], 403);
        }
    }

    // Build student query (multi-tenant safe)
    $studentQuery = User::query()
        ->where('reg_no', $admissionNo)
        ->where('role', 'Student')
        ->where('school_id', $auth->school_id)
        ->with(['level', 'department']);

    // Ã¢Å“â€¦ Restrict teachers to only their assigned class students
    if ($auth->role === 'Teacher') {
        $studentQuery->whereIn('level_id', $allowedLevelIds);
    }

    $student = $studentQuery->first();

    // If teacher searches outside their class, this becomes "not found".
    // If you want it to be explicit, change this to 403 for Teacher.
    if (!$student) {
        return response()->json(['message' => 'You can\'t search for a student outside your assigned class'], 403);
    }

    $schoolId = (int) $auth->school_id;

    $activeTermName = Term::query()
        ->where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->where('status', 'Active')
        ->orderByRaw('COALESCE(sort_order, 999999) ASC')
        ->orderBy('id')
        ->value('name');

    $currentSessionName = AcademicSession::query()
        ->where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->where('is_current', 1)
        ->orderByDesc('id')
        ->value('name');

    if (!$currentSessionName) {
        $currentSessionName = AcademicSession::query()
            ->where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->where('status', 'Active')
            ->orderByDesc('id')
            ->value('name');
    }

    if (!$activeTermName || !$currentSessionName) {
        return response()->json([
            'message' => 'Active term or current academic session is not set for this school. Please configure it in Settings.',
            'term'    => $activeTermName,
            'session' => $currentSessionName,
        ], 422);
    }

    $subjects = $this->subjectService->subjectsForDepartment($schoolId, $student->department_id);

    return response()->json([
        'student'  => $student,
        'term'     => $activeTermName,
        'session'  => $currentSessionName,
        'subjects' => $subjects,
        'warnings' => [],
    ]);
}




public function carryOverPreview(Request $request, User $student)
{
    $auth = Auth::user();

    // security: same school only
    if ($student->school_id !== $auth->school_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $request->validate([
        'session' => 'required|string',
        // optional; if not passed, weÃ¢â‚¬â„¢ll use active term
        'term' => 'nullable|string',
    ]);

    $schoolId = $auth->school_id;
    $sessionName = $request->session;

    // 1) Determine current term name (prefer request->term, else active)
    $currentTermName = $request->term;

    if (!$currentTermName) {
        $active = Term::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->where('status', 'Active')
            ->first();
        $currentTermName = $active?->name;
    }

    if (!$currentTermName) {
        return response()->json(['message' => 'No active term found for this school'], 422);
    }

    // 2) Get ordered terms for school (use sort_order, then id)
    $allTerms = Term::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->orderBy('sort_order')
        ->orderBy('id')
        ->pluck('name')
        ->values()
        ->all();

    // 3) Previous terms = those before current term in that list
    $currentIndex = array_search($currentTermName, $allTerms, true);

    if ($currentIndex === false) {
        // if currentTermName not in terms table (bad data), no carry-over
        return response()->json([
            'terms' => $allTerms,
            'previous_terms' => [],
            'carry_over_preview' => (object)[],
        ]);
    }

    $previousTerms = array_slice($allTerms, 0, $currentIndex);

    if (count($previousTerms) === 0) {
        return response()->json([
            'terms' => $allTerms,
            'previous_terms' => [],
            'carry_over_preview' => (object)[],
        ]);
    }

    // 4) Fetch previous totals per subject per term for that student (same session)
    // results table assumed: results(student_id, subject_id, total, batch_id)
    // result_batches assumed: result_batches(id, school_id, term, session)
    $rows = DB::table('subject_results_v2 as sr')
    ->join('student_results_v2 as st', 'sr.student_result_id', '=', 'st.id')
    ->join('result_batches as rb', 'st.batch_id', '=', 'rb.id')
    ->where('rb.school_id', $schoolId)
    ->where('st.user_id', $student->id)
    ->where('rb.session', $sessionName)
    ->whereIn('rb.term', $previousTerms)
    ->select([
        'sr.subject_id',
        'rb.term',
        DB::raw('CAST(sr.total AS DECIMAL(10,2)) as total'),
        'sr.updated_at',
    ])
    ->orderBy('sr.updated_at', 'desc')
    ->get();


    // 5) Build preview map: subject_id => { termName: total }
    // If multiple records exist for same subject+term, keep latest due to orderBy(updated_at desc)
    $preview = [];
    foreach ($rows as $r) {
        $sid = (string)$r->subject_id;
        if (!isset($preview[$sid])) $preview[$sid] = [];

        if (!array_key_exists($r->term, $preview[$sid])) {
            $preview[$sid][$r->term] = (float)($r->total ?? 0);
        }
    }

    return response()->json([
        'terms' => $allTerms,
        'previous_terms' => $previousTerms,
        'carry_over_preview' => $preview,
    ]);
}



  /**
   * Upsert (insert or update) a student's result for a given batch and student.
   * This saves:
   *  - student_results_v2 (header/summary)
   *  - subject_results_v2 (per-subject rows)
   *  - assessment_scores_v2 (normalized CA components for each subject_result)
   */
  



public function upsert(UpsertStudentResultRequest $request, int $batch, int $student): JsonResponse
{
    $batchRow = DB::table('result_batches')->where('id', $batch)->first();
    if (!$batchRow) {
        return response()->json(['message' => 'Batch not found'], 404);
    }

    if (($batchRow->status ?? null) === 'published') {
        return response()->json([
            'message' => 'This result has already been published. Ask the admin or principal to reopen it before making corrections.',
        ], 409);
    }

    $authUser = $request->user();
    if (! $authUser || (int) $authUser->school_id !== (int) $batchRow->school_id) {
        return response()->json(['message' => 'You are not allowed to access this result batch.'], 403);
    }

    $studentRow = DB::table('users')
        ->where('id', $student)
        ->where('school_id', (int) $batchRow->school_id)
        ->whereRaw('LOWER(role) = ?', ['student'])
        ->first();

    if (! $studentRow || (int) $studentRow->level_id !== (int) $batchRow->class_id) {
        return response()->json(['message' => 'This student does not belong to the selected result batch class.'], 403);
    }

    $role = strtolower((string) $authUser->role);
    if ($role === 'teacher') {
        $assigned = TeacherEnrollment::query()
            ->where('school_id', (int) $batchRow->school_id)
            ->where('user_id', (int) $authUser->id)
            ->where('enroll', '1')
            ->where('level_id', (int) $batchRow->class_id)
            ->exists();

        if (! $assigned) {
            return response()->json(['message' => 'You can only save results for students in your assigned class.'], 403);
        }
    } elseif (! in_array($role, ['admin', 'principal', 'super admin', 'super-admin', 'superadmin'], true)) {
        return response()->json(['message' => 'Only authorized school staff can save results.'], 403);
    }

    $billingStatus = $this->schoolBillingService->resultEntryStatus(
        (int) $batchRow->school_id,
        $student,
        (string) $batchRow->session,
        (string) $batchRow->term
    );

    if (! $billingStatus['allowed']) {
        return response()->json([
            'message' => $billingStatus['message'],
            'billing' => $billingStatus,
        ], 402);
    }

    $columnPolicy = $this->resultColumnPolicy(
        (int) $batchRow->school_id,
        (int) $batchRow->class_id,
        (string) $batchRow->term
    );

    $carryViolation = $this->findCarryOverPolicyViolation($request->input('results', []), $columnPolicy);
    if ($carryViolation) {
        return response()->json([
            'message' => $carryViolation,
            'report_column_policy' => $columnPolicy,
        ], 422);
    }

    $srId = null;

    DB::transaction(function () use ($request, $batch, $student, &$srId) {
        $sr = DB::table('student_results_v2')
            ->where('batch_id', $batch)
            ->where('user_id', $student)
            ->first();

        $meta = $request->input('summary.meta', []);

        $noPresent = $request->input('summary.no_present')
            ?? $meta['no_present'] ?? $meta['present'] ?? null;

        $noAbsent = $request->input('summary.no_absent')
            ?? $meta['no_absent'] ?? $meta['absent'] ?? null;

        $schoolOpen = $request->input('summary.school_open')
            ?? $meta['school_open'] ?? $meta['times_open'] ?? null;

        $schoolClose = $request->input('summary.school_close')
            ?? $meta['school_close'] ?? null;

        $resumptionDate = $request->input('summary.resumption_date')
            ?? $meta['resumption_date'] ?? null;

        if (!isset($meta['no_present']) && $noPresent !== null) $meta['no_present'] = $noPresent;
        if (!isset($meta['no_absent']) && $noAbsent !== null) $meta['no_absent'] = $noAbsent;
        if (!isset($meta['school_open']) && $schoolOpen !== null) $meta['school_open'] = $schoolOpen;
        if (!isset($meta['school_close']) && $schoolClose !== null) $meta['school_close'] = $schoolClose;
        if (!isset($meta['resumption_date']) && $resumptionDate !== null) $meta['resumption_date'] = $resumptionDate;

        $payloadMeta = !empty($meta) ? json_encode($meta) : null;

        if (!$sr) {
            $srId = DB::table('student_results_v2')->insertGetId([
                'batch_id' => $batch,
                'user_id' => $student,

                'rollno' => $request->input('rollno'),
                'department' => $request->input('department'),
                'section_id' => $request->input('section_id'),

                'position' => $request->input('summary.position'),
                'class_teacher' => $request->input('summary.class_teacher'),
                'class_size' => $request->input('summary.class_size'),
                'total_grade' => $request->input('summary.total_grade'),
                'total_average' => $request->input('summary.total_average'),
                'principal_comment' => $request->input('summary.principal_comment'),
                'class_teacher_comment' => $request->input('summary.class_teacher_comment'),
                'general_remark' => $request->input('summary.general_remark'),

                'school_open' => $schoolOpen,
                'school_close' => $schoolClose,
                'no_present' => $noPresent,
                'no_absent' => $noAbsent,
                'resumption_date' => $resumptionDate,

                'meta_json' => $payloadMeta,

                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $srId = $sr->id;

            DB::table('student_results_v2')->where('id', $srId)->update([
                'rollno' => $request->input('rollno'),
                'department' => $request->input('department'),
                'section_id' => $request->input('section_id'),

                'position' => $request->input('summary.position'),
                'class_teacher' => $request->input('summary.class_teacher'),
                'class_size' => $request->input('summary.class_size'),
                'total_grade' => $request->input('summary.total_grade'),
                'total_average' => $request->input('summary.total_average'),
                'principal_comment' => $request->input('summary.principal_comment'),
                'class_teacher_comment' => $request->input('summary.class_teacher_comment'),
                'general_remark' => $request->input('summary.general_remark'),

                'school_open' => $schoolOpen,
                'school_close' => $schoolClose,
                'no_present' => $noPresent,
                'no_absent' => $noAbsent,
                'resumption_date' => $resumptionDate,

                'meta_json' => $payloadMeta,

                'updated_at' => now(),
            ]);
        }

        foreach ($request->input('results') as $row) {
            $existing = DB::table('subject_results_v2')
                ->where('student_result_id', $srId)
                ->where('subject_id', $row['subject_id'])
                ->first();

            $caRaw = is_array($row['ca'] ?? null)
                ? json_encode($row['ca'])
                : ($row['ca'] ?? null);

            $carry = $row['carry_over'] ?? null;

            $carryEnabled = (is_array($carry) && !empty($carry['enabled'])) ? 1 : 0;
            $cumulativeTotal = ($carryEnabled && isset($carry['cumulative_total']))
                ? (float) $carry['cumulative_total']
                : null;
            $cumulativeAverage = ($carryEnabled && isset($carry['cumulative_average']))
                ? (float) $carry['cumulative_average']
                : null;

            $carryJson = $carryEnabled ? json_encode($carry) : null;

            $payload = [
                'student_result_id' => $srId,
                'subject_id' => $row['subject_id'],

                'ca_raw' => $caRaw,
                'exam' => $row['exam'] ?? null,
                'total' => $row['total'] ?? null,
                'grade' => $row['grade'] ?? null,
                'remark' => $row['remark'] ?? null,

                'comment' => $row['comment'] ?? null,
                'signature' => $row['signature'] ?? null,

                'carry_over_json' => $carryJson,
                'carry_over_enabled' => $carryEnabled,
                'cumulative_total' => $cumulativeTotal,
                'cumulative_average' => $cumulativeAverage,

                'updated_at' => now(),
            ];

            if (!$existing) {
                $payload['created_at'] = now();
                $subjectResultId = DB::table('subject_results_v2')->insertGetId($payload);
            } else {
                unset($payload['student_result_id'], $payload['subject_id']);
                DB::table('subject_results_v2')->where('id', $existing->id)->update($payload);
                $subjectResultId = $existing->id;
            }

            if (!empty($row['ca']) && is_array($row['ca'])) {
                DB::table('assessment_scores_v2')
                    ->where('subject_result_id', $subjectResultId)
                    ->delete();

                foreach ($row['ca'] as $k => $v) {
                    $key = is_numeric($k) ? "ca{$k}" : (string) $k;

                    DB::table('assessment_scores_v2')->insert([
                        'subject_result_id' => $subjectResultId,
                        'component_key' => $key,
                        'score' => (float) ($v ?? 0),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    });

    $computeSummary = $this->computeService->computeBatch($batch);

    UpdateResultSubmissionMonitorJob::dispatch($batch)->afterCommit();
    ScanBatchResultAnomaliesJob::dispatch($batch)->afterCommit();

    return response()->json([
        'message' => 'Saved',
        'student_result_id' => $srId,
        'computed' => $computeSummary,
    ]);
}





  public function generateAiComments(Request $request, int $batch, int $student, AiResultCommentGeneratorService $service): JsonResponse
  {
      $authUser = $request->user();
      if (! $authUser) {
          return response()->json(['message' => 'Unauthenticated.'], 401);
      }

      $batchModel = ResultBatch::query()->where('id', $batch)->first();
      if (! $batchModel) {
          return response()->json(['message' => 'Batch not found'], 404);
      }

      if ((int) $authUser->school_id !== (int) $batchModel->school_id) {
          return response()->json(['message' => 'You are not allowed to access this result batch.'], 403);
      }

      $studentModel = User::query()
          ->where('id', $student)
          ->where('school_id', (int) $batchModel->school_id)
          ->whereRaw('LOWER(role) = ?', ['student'])
          ->with(['level', 'department'])
          ->first();

      if (! $studentModel || (int) $studentModel->level_id !== (int) $batchModel->class_id) {
          return response()->json(['message' => 'This student does not belong to the selected result batch class.'], 403);
      }

      $role = strtolower((string) $authUser->role);
      if ($role === 'teacher') {
          $assigned = TeacherEnrollment::query()
              ->where('school_id', (int) $batchModel->school_id)
              ->where('user_id', (int) $authUser->id)
              ->where('enroll', '1')
              ->where('level_id', (int) $batchModel->class_id)
              ->exists();

          if (! $assigned) {
              return response()->json(['message' => 'You can only generate comments for students in your assigned class.'], 403);
          }
      } elseif (! in_array($role, ['admin', 'principal', 'super admin', 'super-admin', 'superadmin'], true)) {
          return response()->json(['message' => 'Only authorized school staff can generate result comments.'], 403);
      }

      $featureAccess = app(SubscriptionGate::class)->inspect($authUser, 'ai_result_comment_generator');
      if (! ($featureAccess['allowed'] ?? false)) {
          return response()->json([
              'message' => $featureAccess['message'] ?? 'AI result comments are not included in your current package.',
              'feature' => $featureAccess,
          ], (int) ($featureAccess['status'] ?? 403));
      }

      $aiCredits = app(SubscriptionAiCreditService::class);
      $creditCost = $aiCredits->costForFeature('ai_result_comment_generator');
      $creditUsage = $aiCredits->assertCreditsAvailable((int) $authUser->school_id, 'ai_result_comment_generator', $creditCost);

      $payload = $request->validate([
          'summary' => ['nullable', 'array'],
          'summary.total_average' => ['nullable', 'numeric'],
          'summary.total_grade' => ['nullable', 'string', 'max:20'],
          'summary.position' => ['nullable', 'string', 'max:50'],
          'summary.class_size' => ['nullable', 'string', 'max:50'],
          'subjects' => ['required', 'array', 'min:1'],
          'subjects.*.subject_name' => ['nullable', 'string', 'max:120'],
          'subjects.*.subject_id' => ['nullable', 'integer'],
          'subjects.*.ca' => ['nullable', 'array'],
          'subjects.*.exam' => ['nullable', 'numeric'],
          'subjects.*.total' => ['nullable', 'numeric'],
          'subjects.*.grade' => ['nullable', 'string', 'max:20'],
          'subjects.*.remark' => ['nullable', 'string', 'max:255'],
          'attendance' => ['nullable', 'array'],
          'behavior_notes' => ['nullable', 'string', 'max:1000'],
          'performance_trend' => ['nullable', 'string', 'max:1000'],
      ]);

      try {
          $result = $service->generate($batchModel, $studentModel, $payload);

          $creditUsage = $aiCredits->consumeCredits((int) $authUser->school_id, 'ai_result_comment_generator', $creditCost, 'ai-result-comment:' . $batch . ':' . $student . ':' . now()->format('YmdHis'), [
              'batch_id' => $batch,
              'student_id' => $student,
          ]);

          $this->logAiResultCommentUsage($request, 'completed', $result['usage'] ?? [], [
              'batch_id' => $batch,
              'student_id' => $student,
              'term' => $batchModel->term,
              'session' => $batchModel->session,
          ], (int) $creditUsage->id, $creditCost);

          return response()->json([
              'message' => 'AI comments generated. Please review before saving.',
              'comments' => $result['comments'],
              'usage' => $result['usage'] ?? null,
              'ai_credits' => [
                  'charged' => $creditCost,
                  'remaining' => $creditUsage->remainingCredits(),
              ],
          ]);
      } catch (\Throwable $e) {
          Log::warning('AI result comment generation failed', [
              'school_id' => $authUser->school_id,
              'user_id' => $authUser->id,
              'batch_id' => $batch,
              'student_id' => $student,
              'error' => $e->getMessage(),
          ]);

          $this->logAiResultCommentUsage($request, 'failed', [], [
              'batch_id' => $batch,
              'student_id' => $student,
              'error' => $e->getMessage(),
          ]);

          return response()->json(['message' => 'Something went wrong while processing this AI request. Please try again later.'], 422);
      }
  }

  private function logAiResultCommentUsage(Request $request, string $status, array $usage = [], array $metadata = [], ?int $creditUsageId = null, int $creditsCharged = 0): void
  {
      if (! Schema::hasTable('ai_usage_logs')) {
          return;
      }

      $row = [
          'school_id' => $request->user()?->school_id,
          'user_id' => $request->user()?->id,
          'feature_key' => 'ai_result_comment_generator',
          'provider' => 'openai',
          'model' => $usage['model'] ?? config('openai.model'),
          'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
          'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
          'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
          'items_generated' => $status === 'completed' ? 3 : 0,
          'status' => $status,
          'metadata' => json_encode($metadata),
          'created_at' => now(),
          'updated_at' => now(),
      ];

      if (Schema::hasColumn('ai_usage_logs', 'subscription_ai_usage_id')) {
          $row['subscription_ai_usage_id'] = $creditUsageId;
      }

      if (Schema::hasColumn('ai_usage_logs', 'credits_charged')) {
          $row['credits_charged'] = $creditsCharged;
      }

      DB::table('ai_usage_logs')->insert($row);
  }

  /**
   * Fetch a student result within a batch (v2 only), including its subject rows.
   */
  public function showInBatch(int $batch, int $student): JsonResponse
  {
    // Get header row for this (batch, student)
    $sr = $this->repo->getV2StudentResult($batch, $student);
    if (!$sr) return response()->json(['message' => 'Result not found'], 404);

    // Get all per-subject results for that header
    $subjects = $this->repo->getV2SubjectResults($sr->id);

    return response()->json(['student_result' => $sr, 'subjects' => $subjects]);
  }

 
 
 
 
 
 
  /**
   * Report card endpoint:
   * - First tries v2 results for the batch (preferred)
   * - Falls back to legacy tables if v2 doesn't exist
   */











public function reportCard(GetReportCardRequest $request): JsonResponse
{
    try {
        $viewer = $request->user();
        if ($viewer && strtolower((string) $viewer->role) === 'student' && (int) $viewer->id !== (int) $request->student_id) {
            return response()->json([
                'message' => 'You are not allowed to view another student result.',
            ], 403);
        }

        if ($viewer) {
            $feeAccess = $this->feeAccessPolicyService->assertResultAccess(
                $viewer,
                (int) $request->school_id,
                (int) $request->student_id,
                (string) $request->session,
                (string) $request->term
            );

            if ($feeAccess) {
                return response()->json([
                    'message' => $feeAccess['message'],
                    'status' => 'fee_access_restricted',
                    'fee_access' => $feeAccess,
                ], 403);
            }
        }

        // Find the matching batch
        $batch = $this->repo->findBatch(
            (int) $request->school_id,
            (int) $request->class_id,
            (string) $request->term,
            (string) $request->session
        );

        // Always load school once (needed for theme + branding)
        $school = SchoolSetting::findOrFail($request->school_id);
        $templateSetting = ResultTemplateSetting::firstOrCreate(
            ['school_id' => (int) $request->school_id],
            ResultTemplateSetting::defaults((int) $request->school_id)
        )->normalized();

        // If batch exists, try v2 result first
        if ($batch) {
            if (! $this->canViewUnpublishedResult($request, $batch)) {
                return response()->json([
                    'message' => 'Result is not yet published. Please check back after the school releases it.',
                    'status' => 'not_published',
                ], 403);
            }

            $sr = $this->repo->getV2StudentResult($batch->id, (int) $request->student_id);

            if ($sr) {
                $subjects = $this->repo->getV2SubjectResults($sr->id);

                // Get additional data
                $student = User::findOrFail($request->student_id);
                $class   = StudentClass::findOrFail($request->class_id);

                // Get student photo
                $photoPath = $student->photo ? public_path('uploads/users/' . $student->photo) : null;
                $photoBase64 = ($photoPath && file_exists($photoPath))
                    ? 'data:' . mime_content_type($photoPath) . ';base64,' . base64_encode(file_get_contents($photoPath))
                    : null;

                // Get domains
                $affective   = $this->repo->getAffectiveDomains($student->id, $request->school_id);
                $psychomotor = $this->repo->getPsychomotorDomains($student->id, $request->school_id);

                // Parse meta_json for additional fields
                $meta = json_decode($sr->meta_json ?? '{}', true);

                return response()->json([
                    'source' => 'v2',
                    'result_status' => $batch->status,
                    'user' => $student,
                    'user_photo_base64' => $photoBase64,
                    'average' => [
                        'id' => $sr->id,
                        'user_id' => $sr->user_id,
                        'class_id' => $class->id,
                        'term' => (string) $request->term,
                        'session' => (string) $request->session,
                        'total_average' => $sr->total_average ?? '0',
                        'total_grade' => $sr->total_grade ?? 'N/A',
                        'position' => $sr->position ?? 'N/A',
                        'class_size' => $sr->class_size ?? '0',
                        'no_present' => $meta['no_present'] ?? $meta['present'] ?? '0',
                        'no_absent' => $meta['no_absent'] ?? $meta['absent'] ?? '0',
                        'school_open' => $meta['school_open'] ?? $meta['times_open'] ?? '0',
                        'class_teacher_comment' => $sr->class_teacher_comment ?? '',
                        'principal_comment' => $sr->principal_comment ?? '',
                        'general_remark' => $sr->general_remark ?? '',
                        'resumption_date' => $meta['resumption_date'] ?? null,
                    ],
                    'term_result' => $subjects,
                    'class_name' => $class->name,
                    'class_section_id' => $class->section_id,
                    'class_section_name' => optional($class->section)->name,

                    'school_info' => [
                        'name' => $school->school_name,
                        'address' => $school->address,
                        'phone' => $school->phone,

                        // base64 images (your existing helper)
                        'logo' => $this->getBase64Image($school->logo),
                        'principal_signature' => $this->getBase64Image($school->principal_signature),

                        // Ã¢Å“â€¦ Theme colors
                        'primary_color' => $school->primary_color ?? '#0d47a1',
                        'secondary_color' => $school->secondary_color ?? '#ffc107',
                        'background_color' => $school->background_color ?? '#ffffff',
                    ],
                    'result_template' => $templateSetting,

                    'affective_domains' => $affective,
                    'psychomotor_domains' => $psychomotor,
                ]);
            }
        }

        // ---------------------------
        // Fallback to legacy results
        // ---------------------------
        $student = User::findOrFail($request->student_id);
        $resultService = new ResultService();

        $legacy = $resultService->build(
            $student,
            (int) $request->class_id,
            (string) $request->term,
            (string) $request->session
        );

        $legacy['source'] = 'legacy';

        // Ã¢Å“â€¦ Ensure school_info exists
        if (!isset($legacy['school_info']) || !is_array($legacy['school_info'])) {
            $legacy['school_info'] = [];
        }

        // Ã¢Å“â€¦ Inject theme colors into legacy payload too
        $legacy['school_info']['primary_color'] = $school->primary_color ?? '#0d47a1';
        $legacy['school_info']['secondary_color'] = $school->secondary_color ?? '#ffc107';
        $legacy['school_info']['background_color'] = $school->background_color ?? '#ffffff';

        // Ã¢Å“â€¦ Optional: make sure legacy also uses base64 images consistently
        // If your legacy builder already returns base64, you can remove these 2 lines.
        if (empty($legacy['school_info']['logo'])) {
            $legacy['school_info']['logo'] = $this->getBase64Image($school->logo);
        }
        if (empty($legacy['school_info']['principal_signature'])) {
            $legacy['school_info']['principal_signature'] = $this->getBase64Image($school->principal_signature);
        }

        // Ã¢Å“â€¦ Also ensure basic school metadata exists
        $legacy['school_info']['name'] = $legacy['school_info']['name'] ?? $school->school_name;
        $legacy['school_info']['address'] = $legacy['school_info']['address'] ?? $school->address;
        $legacy['school_info']['phone'] = $legacy['school_info']['phone'] ?? $school->phone;
        $legacy['result_template'] = $templateSetting;
        $legacyClass = StudentClass::with('section')->find($request->class_id);
        $legacy['class_section_id'] = $legacyClass?->section_id;
        $legacy['class_section_name'] = $legacyClass?->section?->name;

        return response()->json($legacy);

    } catch (\Exception $e) {
        Log::error('Report card error: ' . $e->getMessage());

        return response()->json([
            'message' => 'Result not found: ' . $e->getMessage()
        ], 404);
    }
}

private function canViewUnpublishedResult(Request $request, object $batch): bool
{
    if (($batch->status ?? null) === 'published') {
        return true;
    }

    $user = $request->user();
    if (! $user) {
        return false;
    }

    if ((int) $user->school_id !== (int) $batch->school_id) {
        return false;
    }

    return in_array(strtolower((string) $user->role), ['admin', 'teacher', 'principal', 'super admin', 'super-admin', 'superadmin'], true);
}

private function resultColumnPolicy(int $schoolId, int $classId, string $term): array
{
    $class = StudentClass::find($classId);
    $sectionId = $class?->section_id;
    $setting = ResultTemplateSetting::firstOrCreate(
        ['school_id' => $schoolId],
        ResultTemplateSetting::defaults($schoolId)
    )->normalized();

    $columns = ResultTemplateSetting::DEFAULT_REPORT_COLUMN_OPTIONS;
    $rules = $setting['display_options']['report_column_rules'] ?? [];
    $matched = collect($rules)
        ->filter(function ($rule) use ($sectionId, $term) {
            if (! is_array($rule)) {
                return false;
            }

            $ruleSection = $rule['section_id'] ?? 'all';
            $sectionMatches = $ruleSection === 'all' || (string) $ruleSection === (string) $sectionId;
            $ruleTerm = strtolower(trim((string) ($rule['term'] ?? 'all')));
            $termMatches = $ruleTerm === 'all' || $ruleTerm === strtolower(trim($term));

            return $sectionMatches && $termMatches;
        })
        ->sortBy(function ($rule) use ($sectionId) {
            $sectionScore = (($rule['section_id'] ?? 'all') === 'all') ? 0 : 2;
            $termScore = strtolower(trim((string) ($rule['term'] ?? 'all'))) === 'all' ? 0 : 1;

            return $sectionScore + $termScore;
        });

    foreach ($matched as $rule) {
        $columns = array_merge($columns, is_array($rule['columns'] ?? null) ? $rule['columns'] : []);
    }

    return [
        'section_id' => $sectionId,
        'section_name' => $class?->section?->name,
        'term' => $term,
        'columns' => $columns,
        'carry_over_allowed' => (bool) (
            ($columns['show_first_term'] ?? false)
            || ($columns['show_second_term'] ?? false)
            || ($columns['show_cumulative_total'] ?? false)
            || ($columns['show_cumulative_average'] ?? false)
        ),
    ];
}

private function findCarryOverPolicyViolation(array $rows, array $policy): ?string
{
    $columns = $policy['columns'] ?? [];

    foreach ($rows as $row) {
        $carry = is_array($row['carry_over'] ?? null) ? $row['carry_over'] : null;
        if (! $carry || empty($carry['enabled'])) {
            continue;
        }

        foreach (($carry['terms'] ?? []) as $termName => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalized = strtolower(trim((string) $termName));
            if (str_contains($normalized, 'first') && empty($columns['show_first_term'])) {
                return 'First Term scores are not enabled for this report-card setting. Please update Result Design before including First Term scores.';
            }
            if (str_contains($normalized, 'second') && empty($columns['show_second_term'])) {
                return 'Second Term scores are not enabled for this report-card setting. Please update Result Design before including Second Term scores.';
            }
        }

        if (! empty($carry['cumulative_total']) && empty($columns['show_cumulative_total'])) {
            return 'Cumulative total is not enabled for this report-card setting. Please update Result Design before including cumulative scores.';
        }

        if (! empty($carry['cumulative_average']) && empty($columns['show_cumulative_average'])) {
            return 'Cumulative average is not enabled for this report-card setting. Please update Result Design before including cumulative average.';
        }
    }

    return null;
}


private function getBase64Image($path)
{
    if (!$path || !file_exists(public_path($path))) {
        return null;
    }
    
    return 'data:' . mime_content_type(public_path($path)) . ';base64,' 
           . base64_encode(file_get_contents(public_path($path)));
}
  }






