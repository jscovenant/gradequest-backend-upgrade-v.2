<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Http\Requests\Result\UpsertStudentResultRequest;
use App\Http\Requests\Result\GetReportCardRequest;
use App\Models\AcademicSession;
use App\Models\SchoolSetting;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Repositories\ResultRepository;
use App\Services\Results\SubjectService;
use App\Services\ResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    private SubjectService $subjectService
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

    // ✅ Restrict teachers to only their assigned class students
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
        ->where('status', 'Active')
        ->orderByRaw('COALESCE(sort_order, 999999) ASC')
        ->orderBy('id')
        ->value('name');

    $currentSessionName = AcademicSession::query()
        ->where('school_id', $schoolId)
        ->where('is_current', 1)
        ->orderByDesc('id')
        ->value('name');

    if (!$currentSessionName) {
        $currentSessionName = AcademicSession::query()
            ->where('school_id', $schoolId)
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
        'warnings' => !$student->department_id
            ? ['Student has no department assigned, so no subjects were returned.']
            : [],
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
        // optional; if not passed, we’ll use active term
        'term' => 'nullable|string',
    ]);

    $schoolId = $auth->school_id;
    $sessionName = $request->session;

    // 1) Determine current term name (prefer request->term, else active)
    $currentTermName = $request->term;

    if (!$currentTermName) {
        $active = Term::where('school_id', $schoolId)
            ->where('status', 'Active')
            ->first();
        $currentTermName = $active?->name;
    }

    if (!$currentTermName) {
        return response()->json(['message' => 'No active term found for this school'], 422);
    }

    // 2) Get ordered terms for school (use sort_order, then id)
    $allTerms = Term::where('school_id', $schoolId)
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

    UpdateResultSubmissionMonitorJob::dispatch($batch)->afterCommit();
    ScanBatchResultAnomaliesJob::dispatch($batch)->afterCommit();

    return response()->json([
        'message' => 'Saved',
        'student_result_id' => $srId,
    ]);
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
        // Find the matching batch
        $batch = $this->repo->findBatch(
            (int) $request->school_id,
            (int) $request->class_id,
            (string) $request->term,
            (string) $request->session
        );

        // Always load school once (needed for theme + branding)
        $school = SchoolSetting::findOrFail($request->school_id);

        // If batch exists, try v2 result first
        if ($batch) {
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

                    'school_info' => [
                        'name' => $school->school_name,
                        'address' => $school->address,
                        'phone' => $school->phone,

                        // base64 images (your existing helper)
                        'logo' => $this->getBase64Image($school->logo),
                        'principal_signature' => $this->getBase64Image($school->principal_signature),

                        // ✅ Theme colors
                        'primary_color' => $school->primary_color ?? '#0d47a1',
                        'secondary_color' => $school->secondary_color ?? '#ffc107',
                        'background_color' => $school->background_color ?? '#ffffff',
                    ],

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

        // ✅ Ensure school_info exists
        if (!isset($legacy['school_info']) || !is_array($legacy['school_info'])) {
            $legacy['school_info'] = [];
        }

        // ✅ Inject theme colors into legacy payload too
        $legacy['school_info']['primary_color'] = $school->primary_color ?? '#0d47a1';
        $legacy['school_info']['secondary_color'] = $school->secondary_color ?? '#ffc107';
        $legacy['school_info']['background_color'] = $school->background_color ?? '#ffffff';

        // ✅ Optional: make sure legacy also uses base64 images consistently
        // If your legacy builder already returns base64, you can remove these 2 lines.
        if (empty($legacy['school_info']['logo'])) {
            $legacy['school_info']['logo'] = $this->getBase64Image($school->logo);
        }
        if (empty($legacy['school_info']['principal_signature'])) {
            $legacy['school_info']['principal_signature'] = $this->getBase64Image($school->principal_signature);
        }

        // ✅ Also ensure basic school metadata exists
        $legacy['school_info']['name'] = $legacy['school_info']['name'] ?? $school->school_name;
        $legacy['school_info']['address'] = $legacy['school_info']['address'] ?? $school->address;
        $legacy['school_info']['phone'] = $legacy['school_info']['phone'] ?? $school->phone;

        return response()->json($legacy);

    } catch (\Exception $e) {
        Log::error('Report card error: ' . $e->getMessage());

        return response()->json([
            'message' => 'Result not found: ' . $e->getMessage()
        ], 404);
    }
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

