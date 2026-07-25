<?php

namespace App\Http\Controllers\Backend;


use PDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Models\User;
use App\Models\Level;
use App\Models\Average;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\GradeSetting;
use Illuminate\Http\Request;
use App\Models\SchoolSetting;
use App\Models\SubjectEnroll;
use Illuminate\Support\Carbon;
use App\Models\AcademicSession;
use App\Models\AffectiveDomain;
use App\Models\FirstTermResult;
use App\Models\ThirdTermResult;
use App\Models\GradingForJunior;
use App\Models\GradingForSenior;
use App\Models\SecondTermResult;
use  App\Services\PaymentService;
use App\Models\PsychomotorDomain;
use App\Models\TeacherEnrollment;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Product;
use App\Models\StudentClass;
use App\Models\ParentStudent;

use App\Models\Term;
use Illuminate\Support\Facades\Auth;
use App\Models\UserHasAffectiveDomain;
use PHPUnit\Framework\Constraint\Count;
use App\Models\UserHasPsychomotorDomain;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Traits\HandlesFeatureLimits;
use App\Traits\CheckFeeStatus;
use App\Services\SchoolBillingService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Jobs\SendResultNotification;


class ResultController extends Controller
{
    use HandlesFeatureLimits;
    use CheckFeeStatus;







public function AllResults(Request $request)
{
    $user = Auth::user();

    // Fetch all sessions and all terms (NOT filtering)
    $sessions = AcademicSession::where('school_id', $user->school_id)->get();
    $allTerms = Term::where('school_id', $user->school_id)->pluck('name');

    // Active (still returned, but NOT used to restrict results)
    $activeTerm = Term::where('school_id', $user->school_id)
        ->where('status', 'Active')
        ->first();

    $currentSession = AcademicSession::where('school_id', $user->school_id)
        ->where('is_current', 1)
        ->first();

    // BASE QUERY
    $query = Average::query()
        ->with(['student', 'class'])
        ->where('school_id', $user->school_id);

    // ROLE LOGIC
    if ($user->role === 'Teacher') {

        $teacherClassIds = TeacherEnrollment::where('user_id', $user->id)
            ->pluck('level_id');

        $classes = StudentClass::where('school_id', $user->school_id)
            ->whereIn('id', $teacherClassIds)
            ->get();

        $query->whereIn('class_id', $teacherClassIds);

    } elseif ($user->role === 'student') {

        $classes = [];
        $query->where('user_id', $user->id);

    } elseif ($user->role === 'Parent') {

        $childrenIds = ParentStudent::where('parent_id', $user->id)
            ->pluck('student_id');

        $classes = [];

        if ($childrenIds->isEmpty()) {
            return response()->json([
                'results' => [],
                'classes' => [],
                'sessions' => $sessions,
                'terms' => $allTerms,
                'active_term' => optional($activeTerm)->name,
                'current_session' => optional($currentSession)->name,
                'message' => 'No children linked to this parent'
            ]);
        }

        $query->whereIn('user_id', $childrenIds);

    } else {

        $classes = StudentClass::where('school_id', $user->school_id)->get();

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }
    }

    // -------------------------------
    // FILTERING (Fully flexible)
    // -------------------------------
    if ($request->filled('session')) {
        $session = trim($request->session);
        $query->whereRaw("
            REPLACE(LOWER(session), ' ', '') =
            REPLACE(LOWER(?), ' ', '')
        ", [$session]);
    }

    if ($request->filled('term')) {
        $term = trim($request->term);
        $query->whereRaw("
            REGEXP_REPLACE(LOWER(term), '[^a-z0-9]', '') =
            REGEXP_REPLACE(LOWER(?), '[^a-z0-9]', '')
        ", [$term]);
    }

    // FETCH RESULTS
    $results = $query->latest()->get();

    return response()->json([
        'results' => $results,
        'classes' => $classes,
        'sessions' => $sessions,
        'terms' => $allTerms,
        'active_term' => optional($activeTerm)->name,
        'current_session' => optional($currentSession)->name,
    ]);
}




public function getClasses(Request $request){
    $auth = Auth::user();
    $classes = StudentClass::where('school_id', $auth->school_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $classes,
        ]);
}





public function findByAdmissionNo(string $admissionNo)
{
    $auth = Auth::user();



    // ✅ Now we continue normally
    $student = User::where('reg_no', $admissionNo)
        ->where('role', 'student')
        ->where('school_id', $auth->school_id)
        ->with(['level', 'department'])
        ->first();

    if (!$student) {
        return response()->json(['message' => 'Student not found'], 404);
    }

    $term = Term::where('school_id', $auth->school_id)->latest()->first();
    $session = AcademicSession::where('school_id', $auth->school_id)->latest()->first();

    $subjects = [];
    if ($student->department_id) {
        $subjects = Subject::where('department_id', $student->department_id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    return response()->json([
        'student' => $student,
        'term' => $term?->name,
        'session' => $session?->name,
        'subjects' => $subjects,
    ]);
}



    public function saveResult(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'term' => 'required|string|in:First Term,Second Term,Third Term',
            'session' => 'required|string',
            'class_id' => 'required|integer',
            'department' => 'required|string',
            'section_id' => 'nullable|integer',
            'rollno' => 'required|string',
    
            'results' => 'required|array',
            'results.*.subject_id' => 'required|integer',
            'results.*.ca' => 'nullable|json',
            'results.*.exam' => 'nullable|numeric',
            'results.*.total' => 'nullable|numeric',
            'results.*.grade' => 'nullable|string|max:10',
            'results.*.remark' => 'nullable|string|max:255',
            'results.*.firstterm' => 'nullable|numeric',
            'results.*.secondterm' => 'nullable|numeric',
            'results.*.average' => 'nullable|numeric',
    
            'summary' => 'required|array',
            'summary.total_grade' => 'nullable|string',
            'summary.principal_comment' => 'nullable|string',
            'summary.class_teacher_comment' => 'nullable|string',
            'summary.total_average' => 'nullable|numeric',
            'summary.school_open' => 'nullable|numeric',
            'summary.school_close' => 'nullable|numeric',
            'summary.no_present' => 'nullable|numeric',
            'summary.no_absent' => 'nullable|numeric',
            'summary.general_remark' => 'nullable|string',
            'summary.resumption_date' => 'nullable|date',
            'summary.class_teacher' => 'nullable|string',
            'summary.class_size' => 'nullable|numeric',
            'summary.position' => 'nullable|string',

        ]);
    
        $term = $request->term;
        $userId = $request->user_id;
        $auth = Auth::user();
        $schoolId = $auth->school_id;

        $billingStatus = app(SchoolBillingService::class)->resultEntryStatus(
            (int) $schoolId,
            (int) $userId,
            (string) $request->session,
            (string) $term
        );

        if (! $billingStatus['allowed']) {
            return response()->json([
                'message' => $billingStatus['message'],
                'billing' => $billingStatus,
            ], 402);
        }
    
        // Charge logic inside transaction
       DB::beginTransaction();

try {
    $termModel = match ($term) {
        'First Term' => \App\Models\FirstTermResult::class,
        'Second Term' => \App\Models\SecondTermResult::class,
        'Third Term' => \App\Models\ThirdTermResult::class,
        default => null,
    };

    if (!$termModel) {
        return response()->json([
            'status' => 'error',
            'message' => 'Invalid or unsupported term selected.'
        ], 422);
    }

    // Check if Average exists
    $averageExists = \App\Models\Average::where([
        'user_id'   => $userId,
        'class_id'  => $request->class_id,
        'term'      => $term,
        'session'   => $request->session,
    ])->exists();

    if ($averageExists) {
        return response()->json([
            'status' => 'error',
            'message' => 'Student result already exists for this class, term, and session.'
        ], 409);
    }

  
   
   // ✅ Extract summary FIRST
$summary = $request->summary;

// ✅ Save Average
$average = Average::create([
    'user_id' => $userId,
    'rollno' => $request->rollno,
    'school_id' => $schoolId,
    'class_id' => $request->class_id,
    'term' => $term,
    'session' => $request->session,
    'section_id' => $request->section_id,
    'department' => $request->department,

    'total_grade' => $summary['total_grade'] ?? null,
    'principal_comment' => $summary['principal_comment'] ?? null,
    'class_teacher_comment' => $summary['class_teacher_comment'] ?? null,
    'total_average' => $summary['total_average'] ?? null,
    'school_open' => $summary['school_open'] ?? null,
    'school_close' => $summary['school_close'] ?? null,
    'no_present' => $summary['no_present'] ?? 0,
    'no_absent' => $summary['no_absent'] ?? 0,
    'general_remark' => $summary['general_remark'] ?? null,
    'resumption_date' => $summary['resumption_date'] ?? null,
    'class_teacher' => $summary['class_teacher'] ?? null,
    'class_size' => $summary['class_size'] ?? 0,
    'position' => $summary['position'] ?? null,
]);


    // ✅ Then save each subject result with average_id
    foreach ($request->results as $res) {
        $record = new $termModel();
        $record->user_id = $userId;
        $record->school_id = $schoolId;
        $record->class_id = $request->class_id;
        $record->subject_id = $res['subject_id'];
        $record->ca = $res['ca'] ?? '';
        $record->exam = $res['exam'] ?? '';
        $record->total = $res['total'] ?? '';
        $record->grade = $res['grade'] ?? '';
        $record->remark = $res['remark'] ?? '';
        $record->comment = $res['comment'] ?? '';
        $record->signature = $res['signature'] ?? '';

        // Term-specific fields
        if ($term === 'Second Term') {
            $record->firstterm = $res['firstterm'] ?? '';
            $record->average = $res['average'] ?? '';
        }

        if ($term === 'Third Term') {
            $record->firstterm = $res['firstterm'] ?? '';
            $record->secondterm = $res['secondterm'] ?? '';
            $record->average = $res['average'] ?? '';
        }

        // ✅ Assign the Average ID
        $record->average_id = $average->id;

        $record->save();
    }

    DB::commit();

    return response()->json([
        'status' => 'success',
        'message' => 'Result and average saved successfully.'
    ]);

} catch (\Throwable $e) {
    DB::rollBack();

    return response()->json([
        'status' => 'error',
        'message' => 'An error occurred while saving the result.',
        'debug' => $e->getMessage()
    ], 500);
}

    }
    
    

    public function deleteResult($studentId, $session, $classId, $term)
    {
        $auth = Auth::user();
    
        try {
            $termModel = match ($term) {
                'First Term' => FirstTermResult::class,
                'Second Term' => SecondTermResult::class,
                'Third Term' => ThirdTermResult::class,
                default => null
            };
    
            if (!$termModel) {
                return response()->json(['message' => 'Invalid term.'], 400);
            }
    
            // Get ALL rows (multiple subjects) for the student
            $results = $termModel::where('user_id', $studentId)
                ->where('school_id', $auth->school_id)
                ->where('class_id', $classId)
                ->get();
    
            if ($results->isEmpty()) {
                return response()->json(['message' => 'No results found for deletion.'], 404);
            }
    
            foreach ($results as $result) {
                $result->delete();
            }
    
            // Also delete average record
            Average::where(function ($query) use ($studentId) {
                    $query->Where('user_id', $studentId); 
                })
                ->where('school_id', $auth->school_id)
                ->where('session', $session)
                ->where('class_id', $classId)
                ->where('term', $term)
                ->delete();
    
            return response()->json(['message' => 'All results deleted successfully.']);
    
        } catch (\Exception $e) {
            
            return response()->json(['message' => 'An error occurred.'], 500);
        }
    }
    
    


public function getResultDataByStudent($studentId, $session, $classId, $term)
{
    $term = urldecode($term);
    $auth = Auth::user();

    $termModel = match ($term) {
        'First Term' => \App\Models\FirstTermResult::class,
        'Second Term' => \App\Models\SecondTermResult::class,
        'Third Term' => \App\Models\ThirdTermResult::class,
        default => null,
    };

    if (!$termModel) {
        return response()->json(['message' => 'Invalid term specified'], 400);
    }

    /** ✅ Step 1: Get the EXACT average record */
    $average = \App\Models\Average::where([
        'user_id'    => $studentId,
        'school_id'  => $auth->school_id,
        'class_id'   => $classId,
        'session'    => $session,
        'term'       => $term,
    ])->first();

    if (!$average) {
        return response()->json([
            'message' => 'No result found for this session and term.'
        ], 404);
    }

    /** 🔥 Step 2: Fetch term results ONLY by average_id */
    $results = $termModel::with('subject')
        ->where('average_id', $average->id) // ✅ THIS IS THE FIX
        ->get();

    /** Step 3: Student */
    $student = \App\Models\User::with(['level', 'department'])
        ->findOrFail($studentId);

    if (!$student->department) {
        return response()->json([
            'message' => 'Student has no department assigned.'
        ], 400);
    }

    /** Step 4: All department subjects (frontend filters) */
    $subjects = \App\Models\Subject::where([
        'department_id' => $student->department_id,
        'school_id'     => $auth->school_id,
    ])
        ->select('id', 'name')
        ->orderBy('name')
        ->get();

    /** Step 5: Detect score type */
    $score_type = null;
    if ($results->isNotEmpty()) {
        $firstCa = json_decode($results->first()->ca ?? '{}', true);
        $caCount = count(array_filter(
            array_keys($firstCa),
            fn ($k) => str_starts_with($k, 'ca')
        ));

        $score_type = match ($caCount) {
            4 => '10/10/10/10/60',
            2 => '20/20/60',
            1 => '40/60',
            default => null,
        };
    }

    return response()->json([
        'student'    => $student,
        'results'    => $results,
        'subjects'   => $subjects,
        'average'    => $average,
        'session'    => $session,
        'term'       => $term,
        'score_type' => $score_type,
    ]);
}




public function updateStudentResult(Request $request, $studentId, $session, $classId, $term)
{
    $request->validate([
        'results' => 'required|array',
        'summary' => 'required|array',
    ]);

    $auth = Auth::user();

    $billingStatus = app(SchoolBillingService::class)->resultEntryStatus(
        (int) $auth->school_id,
        (int) $studentId,
        (string) $session,
        (string) $term
    );

    if (! $billingStatus['allowed']) {
        return response()->json([
            'message' => $billingStatus['message'],
            'billing' => $billingStatus,
        ], 402);
    }

    $termModel = match ($term) {
        'First Term' => \App\Models\FirstTermResult::class,
        'Second Term' => \App\Models\SecondTermResult::class,
        'Third Term' => \App\Models\ThirdTermResult::class,
        default => null,
    };

    if (!$termModel) {
        return response()->json(['message' => 'Invalid term.'], 400);
    }

    DB::beginTransaction();

    try {
        // ✅ Create or update Average first
        $average = Average::updateOrCreate(
            [
                'user_id' => $studentId,
                'session' => $session,
                'term' => $term,
                'class_id' => $classId,
            ],
            array_merge($request->summary, [
                'school_id' => $auth->school_id
            ])
        );

        // Delete old term results for this student/class/term/session
        $termModel::where('user_id', $studentId)
            ->where('class_id', $classId)
            ->where('school_id', $auth->school_id)
            ->delete();

        // Save new term results with average_id
        foreach ($request->results as $res) {
            $record = new $termModel();
            $record->user_id = $studentId;
            $record->school_id = $auth->school_id;
            $record->class_id = $classId;
            $record->subject_id = $res['subject_id'] ?? null;
            $record->ca = $res['ca'] ?? '';
            $record->exam = $res['exam'] ?? '';
            $record->total = $res['total'] ?? '';
            $record->grade = $res['grade'] ?? '';
            $record->remark = $res['remark'] ?? '';
            $record->comment = $res['comment'] ?? '';
            $record->signature = $res['signature'] ?? '';

            if ($term === 'Second Term') {
                $record->firstterm = $res['firstterm'] ?? '';
                $record->average = $res['average'] ?? '';
            }

            if ($term === 'Third Term') {
                $record->firstterm = $res['firstterm'] ?? '';
                $record->secondterm = $res['secondterm'] ?? '';
                $record->average = $res['average'] ?? '';
            }

            // ✅ Assign average_id
            $record->average_id = $average->id;

            $record->save();
        }

        DB::commit();

        return response()->json(['message' => 'Result updated successfully.']);

    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Error updating result.',
            'debug' => $e->getMessage()
        ], 500);
    }
}








        public function showStudentResult(Request $request, $id)
        {
            // PUBLIC ACCESS — get student directly
            $user = User::with('level', 'section')->findOrFail($id);

            // Restrict access if student has unpaid fees
        try {
            $this->restrictIfUnpaid($user);
        } catch (HttpException $e) {
            return response()->json([
                'status'  => 'restricted',
                'message' => $e->getMessage()
            ], $e->getStatusCode());
        }

            $classId = $request->query('class_id');
            $term    = $request->query('term');

            // Get full class info
            $class = StudentClass::find($classId);

            $classSize = User::where('level_id', $classId)
                ->where('school_id', $user->school_id)
                ->count();
            
                $session = $request->query('session');
                

        $average = Average::where('user_id', $user->id)
            ->where('class_id', $classId)
            ->where('term', $term)
            ->where('session', $session)
            ->first();

        if (!$average) {
            return response()->json([
                'status' => 'error',
                'message' => 'No result found for selected session.'
            ], 404);
        }

            // Validate term & existence of results
        $resultExists = Average::where('user_id', $user->id)
            ->where('class_id', $classId)
            ->where('term', $term)
            ->where('session', $session)
            ->exists();


            if (!$resultExists) {
                return response()->json([
                    'message' => 'Invalid term selected.',
                    'status' => 'error'
                ], 404);
            }

            // Subjects
            $subjects = Subject::where('school_id', $user->school_id)
                ->where('class_id', $classId)
                ->get();

            // Dynamic model resolution
            $termModelMap = [
                'First Term'  => FirstTermResult::class,
                'Second Term' => SecondTermResult::class,
                'Third Term'  => ThirdTermResult::class,
            ];

            if (!array_key_exists($term, $termModelMap)) {
                return response()->json(['message' => 'Unsupported term.'], 400);
            }

            $resultModel = $termModelMap[$term];

        $termResult = $resultModel::where('user_id', $user->id)
            ->where('average_id', $average->id)
            ->where('class_id', $classId)
            ->with('subject')
            ->get();

            





            // School info
            $school_info = SchoolSetting::find($user->school_id);
            $backgroundColor = $school_info->background_color ?? null;
            $secondaryColor  = $school_info->secondary_color ?? null;
            $primaryColor    = $school_info->primary_color ?? null;

            // =========================
            // PRINCIPAL SIGNATURE (KEPT)
            // =========================
            $principalSignaturePath = $school_info->principal_signature
                ? public_path($school_info->principal_signature)
                : null;

            $principalSignatureBase64 = null;

            if ($principalSignaturePath && file_exists($principalSignaturePath)) {
                $mime = mime_content_type($principalSignaturePath);
                $principalSignatureBase64 =
                    'data:' . $mime . ';base64,' .
                    base64_encode(file_get_contents($principalSignaturePath));
            }

            // Student photo
            $userPhotoPath = $user->photo
                ? public_path('uploads/users/' . $user->photo)
                : null;

            $userPhotoBase64 = null;

            if ($userPhotoPath && file_exists($userPhotoPath)) {
                $mime = mime_content_type($userPhotoPath);
                $userPhotoBase64 =
                    'data:' . $mime . ';base64,' .
                    base64_encode(file_get_contents($userPhotoPath));
            }

            // School logo
            $logoPath = $school_info->logo
                ? public_path($school_info->logo)
                : null;

            $logoBase64 = null;

            if ($logoPath && file_exists($logoPath)) {
                $mime = mime_content_type($logoPath);
                $logoBase64 =
                    'data:' . $mime . ';base64,' .
                    base64_encode(file_get_contents($logoPath));
            }

            // Ratings map
            $ratings = [
                1 => 'Poor',
                2 => 'Good',
                3 => 'Very Good',
                4 => 'Excellent',
            ];

            // Affective domains
            $affectiveDomains = DB::table('user_has_affective_domains as uhd')
                ->join('affective_domains as ad', 'uhd.affective_id', '=', 'ad.id')
                ->where('uhd.user_id', $user->id)
                ->where('uhd.school_id', $user->school_id)
                ->orderBy('uhd.updated_at', 'DESC')
                ->select('ad.title as domain', 'uhd.rate')
                ->get()
                ->unique('domain')
                ->values()
                ->map(fn ($row) => [
                    'domain' => $row->domain,
                    'rating' => $ratings[$row->rate] ?? 'Not Rated',
                ]);

            // Psychomotor domains
            $psychomotorDomains = DB::table('user_has_psychomotor_domains as upd')
                ->join('psychomotor_domains as pd', 'upd.psychomotor_id', '=', 'pd.id')
                ->where('upd.user_id', $user->id)
                ->where('upd.school_id', $user->school_id)
                ->orderBy('upd.updated_at', 'DESC')
                ->select('pd.title as domain', 'upd.rate')
                ->get()
                ->unique('domain')
                ->values()
                ->map(fn ($row) => [
                    'domain' => $row->domain,
                    'rating' => $ratings[$row->rate] ?? 'Not Rated',
                ]);

            // Grading system
            $sectionName = optional($user->section)->name;

            if ($sectionName === 'Junior') {
                $grades = GradingForJunior::where('school_id', $user->school_id)->get();
            } elseif ($sectionName === 'Senior') {
                $grades = GradingForSenior::where('school_id', $user->school_id)->get();
            } else {
                $grades = [];
            }

            return response()->json([
            'term' => $term,
            'user' => $user,
            'user_photo_base64' => $userPhotoBase64,

            'class_id' => $classId,
            'class_name' => optional($average->class)->name,

            // TERM SCORES (ONLY scores)
            'term_result' => $termResult,

            // AVERAGE (ALL summary/meta)
            'average' => [
                'position' => $average->position,
                'class_teacher' => $average->class_teacher,
                'class_size' => $average->class_size,
                'total_grade' => $average->total_grade,
                'total_average' => $average->total_average,
                'principal_comment' => $average->principal_comment,
                'class_teacher_comment' => $average->class_teacher_comment,
                'general_remark' => $average->general_remark,
                'resumption_date' => $average->resumption_date,
                'school_open' => $average->school_open,
                'school_close' => $average->school_close,
                'no_present' => $average->no_present,
                'no_absent' => $average->no_absent,
                'term' => $average->term,
                'session' => $average->session,
            ],

            // STATIC DATA
            'grades' => $grades,
            'affective_domains' => $affectiveDomains,
            'psychomotor_domains' => $psychomotorDomains,

            'school_info' => [
                'name' => $school_info->school_name,
                'phone' => $school_info->phone,
                'address' => $school_info->address,
                'logo' => $logoBase64,
                'principal_signature' => $principalSignatureBase64,
                'backgroundColor' => $backgroundColor,
                'secondaryColor' => $secondaryColor,
                'primaryColor' => $primaryColor,
            ],
        ], 200);

        }




 public function broadsheetOptions()
    {
        $schoolId = Auth::user()->school_id;

        $classes = \App\Models\StudentClass::select('id', 'name')->where('school_id', $schoolId)
            ->orderBy('name')
            ->get();

        $sessions = \App\Models\AcademicSession::where('school_id', $schoolId)
            ->pluck('name')
            ->unique()
            ->values();

        $terms = ['First Term', 'Second Term', 'Third Term'];

        return response()->json([
            'classes' => $classes,
            'sessions' => $sessions,
            'terms' => $terms,
        ]);
    }



public function fetchBroadsheet(Request $request)
{
    $schoolId = Auth::user()->school_id;

    $classId = $request->query('classId');
    $session = $request->query('session');
    $term = $request->query('term');

    if (!$classId || !$session || !$term) {
        return response()->json(['message' => 'classId, session, and term are required.'], 400);
    }

    $termModel = match ($term) {
        'First Term' => FirstTermResult::class,
        'Second Term' => SecondTermResult::class,
        'Third Term' => ThirdTermResult::class,
        default => null
    };

    if (!$termModel) {
        return response()->json(['message' => 'Invalid term.'], 400);
    }

    // --- Only students who have an average for this session/term/class ---
    $studentIds = Average::where('school_id', $schoolId)
        ->where('class_id', $classId)
        ->where('session', $session)
        ->where('term', $term)
        ->pluck('user_id')
        ->unique();

    if ($studentIds->isEmpty()) {
        return response()->json([
            'students' => [],
            'subjects' => [],
        ]);
    }

    $students = User::whereIn('id', $studentIds)->with('level')->get();

    $data = [];
    $allSubjects = [];

    foreach ($students as $student) {
        // fetch term results only for this student
        $results = $termModel::where('user_id', $student->id)
            ->where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->get();

        $average = Average::where('user_id', $student->id)
            ->where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->where('session', $session)
            ->where('term', $term)
            ->first();

        $subjects = [];
        $total = 0;

        foreach ($results as $res) {
            $subjectName = optional($res->subject)->name;
            if ($subjectName) {
                $subjects[$subjectName] = $res->total;
                $allSubjects[] = $subjectName;
                $total += $res->total;
            }
        }

        $data[] = [
            'id' => $student->id,
            'admissionNo' => $student->reg_no ?? 'N/A',
            'name' => trim("{$student->surname} {$student->firstname} {$student->third_name}"),
            'class' => optional($student->level)->name,
            'session' => $session,
            'term' => $term,
            'scores' => $subjects,
            'total' => $total,
            'average' => $average->total_average ?? 0,
        ];
    }

    $uniqueSubjects = array_values(array_unique($allSubjects));

    return response()->json([
        'students' => $data,
        'subjects' => $uniqueSubjects,
    ]);
}




public function verifyResult(Request $request)
{
    $request->validate([
        'studentId' => 'required|integer',
        'reg_no' => 'required|string',
        'term' => 'required|string',
        'session' => 'required|string'
    ]);

    // Validate the student
    $student = User::where('id', $request->studentId)
        ->where('reg_no', $request->reg_no)
        ->first();

    if (!$student) {
        return response()->json([
            'message' => 'Invalid or tampered QR code.'
        ], 404);
    }
    


    // Get result
    $average = Average::where('user_id', $student->id)
        ->where('term', $request->term)
        ->where('session', $request->session)
        ->first();

    if (!$average) {
        return response()->json([
            'message' => 'No matching result found.'
        ], 404);
    }

    $class = Section::find($average->section_id);
    $school = SchoolSetting::find($student->school_id);

    return response()->json([
        'student' => $student,
        'result' => [
            'class' => $class?->name,
            'term' => $average->term,
            'session' => $average->session,
            'total_average' => $average->total_average,
            'total_grade' => $average->total_grade,
            'general_remark' => $average->general_remark
        ],
        'school' => $school
    ]);
}




// app/Http/Controllers/NotificationController.php

// public function broadcastResults(Request $request)
// {
//     $request->validate([
//         'school_id' => 'required|integer',
//         'class_id'  => 'required|integer',
//         'term'      => 'required|in:First Term,Second Term,Third Term',
//         'session'   => 'required|string',
//     ]);

//     // Get all students in this class with an average record
//     $students = Average::where('class_id', $request->class_id)
//         ->where('term', $request->term)
//         ->where('session', $request->session)
//         ->whereHas('user', fn($q) => $q->where('school_id', $request->school_id))
//         ->pluck('user_id');

//     $loop = 0;
//     foreach ($students as $studentId) {
//         SendResultNotification::dispatch(
//             $studentId,
//             $request->class_id,
//             $request->term,
//             $request->session
//         )->delay(now()->addSeconds($loop++ * 3)); // 3s stagger per student
//     }

//     return response()->json([
//         'message' => "{$students->count()} result PDFs queued for WhatsApp delivery."
//     ]);
// }
    


}
