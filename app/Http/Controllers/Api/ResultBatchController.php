<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Result\ResolveBatchRequest;
use App\Http\Requests\Result\UpsertStudentResultRequest;
use App\Models\ResultBatch;
use App\Repositories\ResultRepository;
use App\Services\Results\ResultComputeService;
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
   * ResultRepository      → Handles DB read/write abstraction for results
   * ResultComputeService  → Contains batch computation logic (positions, ranking, etc.)
   * SubjectService        → Returns subjects based on department (your business rule)
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

    // ✅ Teacher can only resolve batch for assigned class(es)
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

    $activeTermName = Term::query()
        ->where('school_id', $schoolId)
        ->where('status', 'Active')
        ->orderBy('sort_order', 'asc')
        ->orderBy('id', 'asc')
        ->value('name');

    if (!$activeTermName) {
        return response()->json([
            'message' => 'No ACTIVE term set for this school.'
        ], 422);
    }

    $batch = $this->repo->resolveBatch(
        $schoolId,
        (int) $request->class_id,
        (string) $activeTermName,         
        (string) $request->session,
        (int) $user->id
    );

    return response()->json([
        'batch' => $batch,
        'active_term' => $activeTermName,
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
    $this->computeService->computeBatch($batch);

    return response()->json(['message' => 'Batch computation started']);
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

    $activeTermName = Term::query()
        ->where('school_id', $schoolId)
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
                'exam' => is_null($r->exam) ? null : (int) $r->exam,
                'total' => is_null($r->total) ? null : (int) $r->total,
                'grade' => $r->grade,
                'remark' => $r->remark,
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
        'billing' => $billingStatus,
        'warnings' => !$student->department_id
            ? ['Student has no department assigned, so no subjects were returned.']
            : [],
    ]);
}









}
