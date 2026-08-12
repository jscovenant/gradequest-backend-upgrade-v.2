<?php

namespace App\Http\Controllers\Api;

use App\Events\ResultPublished;
use App\Http\Controllers\Controller;
use App\Http\Requests\Result\ResolveBatchRequest;
use App\Http\Requests\Result\UpsertStudentResultRequest;
use App\Models\ResultBatch;
use App\Models\ResultTemplateSetting;
use App\Repositories\ResultRepository;
use App\Services\Results\ResultComputeService;
use App\Services\Results\ResultExcelImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Results\SubjectService;
use App\Services\SchoolBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\TeacherEnrollment;

/**
 * ResultBatchController
 *
 * This controller manages:
 *  - Creating or resolving result batches
 *  - Listing students inside a batch
 *  - Returning result form data (student + subjects + existing result)
 *  - Triggering batch computation (positions, averages, etc.)
 */
class ResultBatchController extends Controller
{
  /**
   * Inject core services:
   *
   * ResultRepository      Ã¢â€ â€™ Handles DB read/write abstraction for results
   * ResultComputeService  Ã¢â€ â€™ Contains batch computation logic (positions, ranking, etc.)
   * SubjectService        Ã¢â€ â€™ Returns subjects based on department (your business rule)
   */
  public function __construct(
    private ResultRepository $repo,
    private ResultComputeService $computeService,
    private SubjectService $subjectService,
    private SchoolBillingService $schoolBillingService
  ) {}

  /**
   * Resolve (or create if not exists) a result batch.
   *
   * A "batch" uniquely represents:
   *  - school_id
   *  - class_id
   *  - term
   *  - session
   *
   * This ensures all students in the same class/term/session
   * are grouped under one batch.
   */



public function resolve(ResolveBatchRequest $request): JsonResponse
{
       $user = $request->user();
    $schoolId = (int) $user->school_id;
    $classId = (int) $request->class_id;

    if (!$schoolId) {
        return response()->json(['message' => 'School not found for this user. Please contact admin.'], 422);
    }

    // Ã¢Å“â€¦ Teacher can only resolve batch for assigned class(es)
    if ($user->role === 'Teacher') {
        $allowed = TeacherEnrollment::where('school_id', $schoolId)
            ->where('user_id', $user->id)
            ->where('enroll', '1')
            ->where('level_id', $classId)
            ->exists();

        if (!$allowed) {
            return response()->json([
                'message' => 'You are not allowed to create a batch for this class.'
            ], 403);
        }
    }

    $selectedTermName = trim((string) $request->term);

    $termExists = Term::query()
        ->where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->whereRaw('LOWER(name) = ?', [strtolower($selectedTermName)])
        ->exists();

    if (!$termExists) {
        return response()->json([
            'message' => 'The selected term was not found for this school.'
        ], 422);
    }

    $batch = $this->repo->resolveBatch(
        $schoolId,
        (int) $request->class_id,
        $selectedTermName,
        (string) $request->session,
        (int) $user->id
    );

    return response()->json([
        'batch' => $batch,
        'selected_term' => $selectedTermName,
    ]);
}



  /**
   * Show a specific batch by ID.
   */
  public function show(int $batch): JsonResponse
  {
    $data = DB::table('result_batches')->where('id', $batch)->first();

    if (!$data) {
      return response()->json(['message' => 'Batch not found'], 404);
    }

    return response()->json(['batch' => $data]);
  }

  /**
   * Trigger computation for a batch.
   *
   * This usually:
   *  - Calculates averages
   *  - Assigns positions
   *  - Updates ranking fields
   */
  public function compute(int $batch): JsonResponse
  {
    $summary = $this->computeService->computeBatch($batch);

    return response()->json([
        'message' => 'Batch computed successfully',
        'computed' => $summary,
    ]);
  }

  public function resultImportTemplate(Request $request, ResultBatch $batch, ResultExcelImportService $importService)
  {
    $this->ensureCanEnterBatchResults($request, $batch);

    return $importService->template(
      $batch,
      (string) $request->query('format', 'xls'),
      (string) $request->query('assessment_format', 'ca_exam'),
      $request->query('department_id') ? (int) $request->query('department_id') : null
    );
  }

  public function previewResultImport(Request $request, ResultBatch $batch, ResultExcelImportService $importService): JsonResponse
  {
    $this->ensureCanEnterBatchResults($request, $batch);

    $request->validate([
      'file' => ['required', 'file', 'max:5120'],
      'department_id' => ['nullable', 'integer'],
    ]);

    return response()->json($importService->preview(
      $batch,
      $request->file('file'),
      $request->input('department_id') ? (int) $request->input('department_id') : null
    ));
  }

  public function importResults(Request $request, ResultBatch $batch, ResultExcelImportService $importService): JsonResponse
  {
    $this->ensureCanEnterBatchResults($request, $batch);

    $request->validate([
      'file' => ['required', 'file', 'max:5120'],
      'department_id' => ['nullable', 'integer'],
    ]);

    return response()->json($importService->import(
      $batch,
      $request->file('file'),
      $request->input('department_id') ? (int) $request->input('department_id') : null
    ));
  }

  public function reviewSummary(Request $request, ResultBatch $batch): JsonResponse
  {
    $this->ensureSameSchool($request, $batch);

    return response()->json([
      'batch' => $batch->fresh(),
      'review' => $this->batchReviewSummary($batch),
    ]);
  }

  public function approve(Request $request, ResultBatch $batch): JsonResponse
  {
    $this->ensureCanManageResultPublication($request, $batch);

    $summary = $this->batchReviewSummary($batch);
    if (! $summary['is_complete']) {
      return response()->json([
        'message' => 'Some students do not have saved results yet. Complete all student results before approval.',
        'review' => $summary,
      ], 422);
    }

    if ($summary['open_high_alerts'] > 0) {
      return response()->json([
        'message' => 'Some result alerts still need review before approval.',
        'review' => $summary,
      ], 422);
    }

    $batch->forceFill([
      'status' => 'approved',
      'approved_at' => now(),
      'approved_by' => $request->user()->id,
      'review_note' => $request->input('note'),
      'updated_at' => now(),
    ])->save();

    return response()->json([
      'message' => 'Results approved. They are not yet visible to parents and students until published.',
      'batch' => $batch->fresh(),
      'review' => $summary,
    ]);
  }

  public function publish(Request $request, ResultBatch $batch): JsonResponse
  {
    $this->ensureCanManageResultPublication($request, $batch);

    $summary = $this->batchReviewSummary($batch);
    if (! $summary['is_complete']) {
      return response()->json([
        'message' => 'Some students do not have saved results yet. Complete all student results before publishing.',
        'review' => $summary,
      ], 422);
    }

    if (! in_array($batch->status, ['approved', 'published'], true)) {
      return response()->json([
        'message' => 'Approve the results before publishing them.',
        'review' => $summary,
      ], 422);
    }

    if ($summary['open_high_alerts'] > 0) {
      return response()->json([
        'message' => 'Some result alerts still need review before publishing.',
        'review' => $summary,
      ], 422);
    }

    $batch->forceFill([
      'status' => 'published',
      'published_at' => now(),
      'published_by' => $request->user()->id,
      'review_note' => $request->input('note', $batch->review_note),
      'updated_at' => now(),
    ])->save();

    DB::afterCommit(fn () => ResultPublished::dispatch((int) $batch->id, (int) $batch->school_id));

    return response()->json([
      'message' => 'Results published. Parents and students can now view them.',
      'batch' => $batch->fresh(),
      'review' => $summary,
    ]);
  }

  public function reopen(Request $request, ResultBatch $batch): JsonResponse
  {
    $this->ensureCanManageResultPublication($request, $batch);

    $batch->forceFill([
      'status' => 'computed',
      'approved_at' => null,
      'approved_by' => null,
      'published_at' => null,
      'published_by' => null,
      'review_note' => $request->input('note'),
      'updated_at' => now(),
    ])->save();

    return response()->json([
      'message' => 'Results reopened for correction. Parents and students cannot view this batch until it is published again.',
      'batch' => $batch->fresh(),
      'review' => $this->batchReviewSummary($batch->fresh()),
    ]);
  }

  /**
   * Return all students in a given batch.
   *
   * Route model binding is used:
   *   ResultBatch $batch
   */


public function students(Request $request, ResultBatch $batch): JsonResponse
{
  $userSchoolId = (int) ($request->user()?->school_id ?? 0);

  if ($userSchoolId && (int) $batch->school_id !== $userSchoolId) {
    return response()->json(['message' => 'Unauthorized batch access'], 403);
  }

  $students = User::query()
    ->where('users.school_id', $batch->school_id)
    ->where('users.role', 'Student')
    ->where('users.level_id', $batch->class_id)
    ->leftJoin('student_results_v2 as sr', function ($join) use ($batch) {
      $join->on('sr.user_id', '=', 'users.id')
           ->where('sr.batch_id', '=', $batch->id);
    })
    ->orderBy('users.reg_no')
    ->select([
      'users.id',
      'users.reg_no',
      'users.firstname',
      'users.surname',
      'users.photo',
      'sr.id as student_result_id',
      'sr.updated_at as saved_at',
    ])
    ->get()
    ->map(function ($s) {
      $completed = !empty($s->student_result_id);

      return [
        'id' => (int) $s->id,
        'reg_no' => $s->reg_no ?? '',
        'firstname' => $s->firstname ?? '',
        'surname' => $s->surname ?? '',
        'photo' => $s->photo_url ?? null,
        'status' => $completed ? 'completed' : 'pending',
        'saved_at' => $completed ? $s->saved_at : null,
      ];
    })
    ->values();

  return response()->json(['data' => $students]);
}

private function ensureSameSchool(Request $request, ResultBatch $batch): void
{
  $user = $request->user();

  abort_if(! $user || (int) $batch->school_id !== (int) $user->school_id, 403, 'You are not allowed to access this result batch.');
}

private function ensureCanEnterBatchResults(Request $request, ResultBatch $batch): void
{
  $this->ensureSameSchool($request, $batch);

  $user = $request->user();
  $role = strtolower((string) $user->role);

  if (in_array($role, ['admin', 'principal', 'super admin', 'super-admin', 'superadmin'], true)) {
    return;
  }

  abort_if($role !== 'teacher', 403, 'Only authorized school staff can upload results.');

  $assigned = TeacherEnrollment::query()
    ->where('school_id', (int) $batch->school_id)
    ->where('user_id', (int) $user->id)
    ->where('enroll', '1')
    ->where('level_id', (int) $batch->class_id)
    ->exists();

  abort_if(! $assigned, 403, 'You can only upload results for students in your assigned class.');
}

private function ensureCanManageResultPublication(Request $request, ResultBatch $batch): void
{
  $this->ensureSameSchool($request, $batch);

  $role = strtolower((string) $request->user()->role);
  abort_if(! in_array($role, ['admin', 'principal', 'super admin', 'super-admin', 'superadmin'], true), 403, 'Only the admin or principal can approve and publish results.');
}

private function batchReviewSummary(ResultBatch $batch): array
{
  $totalStudents = User::query()
    ->where('school_id', $batch->school_id)
    ->where('role', 'Student')
    ->where('level_id', $batch->class_id)
    ->count();

  $completedStudents = DB::table('student_results_v2 as sr')
    ->join('users as u', 'u.id', '=', 'sr.user_id')
    ->where('sr.batch_id', $batch->id)
    ->where('u.school_id', $batch->school_id)
    ->where('u.role', 'Student')
    ->where('u.level_id', $batch->class_id)
    ->distinct('user_id')
    ->count('sr.user_id');

  $missingStudents = User::query()
    ->where('users.school_id', $batch->school_id)
    ->where('users.role', 'Student')
    ->where('users.level_id', $batch->class_id)
    ->leftJoin('student_results_v2 as sr', function ($join) use ($batch) {
      $join->on('sr.user_id', '=', 'users.id')
           ->where('sr.batch_id', '=', $batch->id);
    })
    ->whereNull('sr.id')
    ->orderBy('users.surname')
    ->orderBy('users.firstname')
    ->limit(20)
    ->get(['users.id', 'users.firstname', 'users.surname', 'users.reg_no']);

  $openAlerts = DB::table('academic_alerts')
    ->where('batch_id', $batch->id)
    ->where('status', 'open')
    ->count();

  $openHighAlerts = DB::table('academic_alerts')
    ->where('batch_id', $batch->id)
    ->where('status', 'open')
    ->whereIn('severity', ['high', 'critical'])
    ->count();

  $missingCount = max(0, $totalStudents - $completedStudents);

  $simpleStatus = match ($batch->status) {
    'draft' => 'Teachers are still entering results.',
    'computed' => 'Results are ready for admin review.',
    'approved' => 'Results have been approved and are waiting to be published.',
    'published' => $missingCount > 0
      ? "{$missingCount} student(s) in this class still do not have a result in this published batch."
      : 'Results have been published for parents and students.',
    default => 'Result status is unknown.',
  };

  return [
    'status' => $batch->status,
    'total_students' => $totalStudents,
    'completed_students' => $completedStudents,
    'missing_students_count' => $missingCount,
    'missing_students' => $missingStudents,
    'open_alerts' => $openAlerts,
    'open_high_alerts' => $openHighAlerts,
    'is_complete' => $totalStudents > 0 && $missingCount === 0,
    'can_approve' => $totalStudents > 0 && $missingCount === 0 && $openHighAlerts === 0,
    'can_publish' => in_array($batch->status, ['approved', 'published'], true) && $totalStudents > 0 && $missingCount === 0 && $openHighAlerts === 0,
    'simple_status' => $simpleStatus,
  ];
}

   /**
     * Best UX: return students + completed/pending status in one call.
     * Pending = student has NO student_results_v2 row for this batch.
     */
 

public function resultForm(int $batchId, int $studentId)
{
    $authUser = request()->user();
    $schoolId = (int) $authUser->school_id;

    if (!$schoolId) {
        return response()->json(['message' => 'School not found for this user.'], 422);
    }

    // Batch MUST belong to this school (align with resolve)
    $batch = ResultBatch::where('school_id', $schoolId)->findOrFail($batchId);

    // Student MUST belong to this school
    $student = User::with(['level', 'department'])
        ->where('school_id', $schoolId)
        ->findOrFail($studentId);

    $this->ensureCanEnterBatchResults(request(), $batch);
    if ((int) $student->level_id !== (int) $batch->class_id) {
        return response()->json([
            'message' => 'This student does not belong to the selected result batch class.',
        ], 403);
    }

    $activeTermName = Term::query()
        ->where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->where('status', 'Active')
        ->orderBy('sort_order', 'asc')
        ->orderBy('id', 'asc')
        ->value('name');

    // OPTION B: block mismatch
    if ($activeTermName && strcasecmp((string) $activeTermName, (string) $batch->term) !== 0) {
        return response()->json([
            'message' => "Batch term ({$batch->term}) does not match ACTIVE term ({$activeTermName}). Please resolve a new batch for the active term.",
            'batch_term' => $batch->term,
            'active_term' => $activeTermName,
            'batch_id' => $batch->id,
        ], 409);
    }

    $billingStatus = $this->schoolBillingService->resultEntryStatus(
        $schoolId,
        $student->id,
        (string) $batch->session,
        (string) $batch->term
    );

    if (! $billingStatus['allowed']) {
        return response()->json([
            'message' => $billingStatus['message'],
            'billing' => $billingStatus,
        ], 402);
    }

    // Subjects
    $subjects = $this->subjectService->subjectsForDepartment(
        $schoolId,
        $student->department_id
    );

    // Existing results
    $existing = $this->repo->getV2StudentResult($batch->id, $student->id);
    $existingPayload = null;

    if ($existing) {
        $rows = $this->repo->getV2StudentResultRows($existing->id);

        $normalizedRows = $rows->map(function ($r) {
            return [
                'subject_id' => (int) $r->subject_id,
                'ca' => $r->ca_raw ? json_decode($r->ca_raw, true) : [],
                'ca_total' => isset($r->ca_total) ? (float) $r->ca_total : null,
                'exam' => is_null($r->exam) ? null : (int) $r->exam,
                'total' => is_null($r->total) ? null : (int) $r->total,
                'grade' => $r->grade,
                'remark' => $r->remark,
                'subject_position' => $r->subject_position ?? null,
                'carry_over' => $r->carry_over_json ? json_decode($r->carry_over_json, true) : null,
            ];
        });

        $existingPayload = [
            'summary' => [
                'position' => $existing->position,
                'class_teacher' => $existing->class_teacher,
                'class_size' => $existing->class_size,
                'total_grade' => $existing->total_grade,
                'total_average' => $existing->total_average,
                'principal_comment' => $existing->principal_comment,
                'class_teacher_comment' => $existing->class_teacher_comment,
                'general_remark' => $existing->general_remark,
                'meta' => $existing->meta_json ? json_decode($existing->meta_json, true) : [],
            ],
            'results' => $normalizedRows,
        ];
    }

    // Terms ordering (all your sort_order are 0, so ID order matters)
    $terms = Term::query()
        ->where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->orderBy('sort_order', 'asc')
        ->orderBy('id', 'asc')
        ->get(['name', 'sort_order']);

    $termNames = $terms->pluck('name')->values();

    $currentTerm = (string) $batch->term;
    $currentIndex = $termNames->search(fn ($t) => strcasecmp((string) $t, $currentTerm) === 0);

    $previousTerms = [];
    if ($currentIndex !== false) {
        $previousTerms = $termNames->slice(0, $currentIndex)->values()->all();
    }

    $carryOverPreview = [];
    foreach ($previousTerms as $tName) {
        $rows = DB::table('result_batches as rb')
            ->join('student_results_v2 as sr', 'sr.batch_id', '=', 'rb.id')
            ->join('subject_results_v2 as subr', 'subr.student_result_id', '=', 'sr.id')
            ->where('rb.school_id', $schoolId)
            ->where('rb.class_id', $batch->class_id)
            ->where('rb.session', $batch->session)
            ->where('rb.term', $tName)
            ->where('sr.user_id', $student->id)
            ->select(['subr.subject_id', 'subr.total'])
            ->get();

        foreach ($rows as $r) {
            $sid = (int) $r->subject_id;
            $total = is_null($r->total) ? null : (float) $r->total;
            if ($total !== null) {
                $carryOverPreview[$sid] ??= [];
                $carryOverPreview[$sid][$tName] = $total;
            }
        }
    }

    return response()->json([
        'batch' => $batch,
        'student' => $student,
        'subjects' => $subjects,
        'term' => $batch->term,
        'session' => $batch->session,
        'existing' => $existingPayload,
        'terms' => $termNames,
        'previous_terms' => $previousTerms,
        'carry_over_preview' => $carryOverPreview,
        'report_column_policy' => $this->resultColumnPolicy($schoolId, (int) $batch->class_id, (string) $batch->term),
        'billing' => $billingStatus,
        'warnings' => [],
    ]);
}






private function resultColumnPolicy(int $schoolId, int $classId, string $term): array
{
    $class = \App\Models\StudentClass::find($classId);
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
        ->sortBy(function ($rule) {
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




}


