<?php

namespace App\Http\Controllers\Backend;

use App\Models\User;

use Illuminate\Http\Request;

use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\Average;


use Illuminate\Support\Carbon;
use App\Mail\VerificationCodeMail;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\ParentStudent;
use App\Models\StudentFee;
use App\Models\Term;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminDashboardController extends Controller
{
       public function getDashboardCounts(Request $request)
{
    $auth = Auth::user();

    // === TOTAL SCHOOLS, ADMINS, RESULTS ===
    $totalSchools = SchoolSetting::count();
    $totalAdmins  = User::where('role', 'Admin')->count();
    $totalResults = Average::count();

    $normRole = User::normalizeRole($auth->role);
    $isPrincipal = $normRole === 'principal' || (method_exists($auth, 'isPrincipal') && $auth->isPrincipal());

    if ($isPrincipal && !empty($auth->getPrincipalAssignedSectionIds())) {
        $assignedSectionIds = $auth->getPrincipalAssignedSectionIds();

        // Students in assigned section(s) (only active students)
        $studentsCount = User::where('school_id', $auth->school_id)
            ->whereRaw("LOWER(role) = 'student'")
            ->where(function ($q) {
                $q->where('status', 1)->orWhereNull('status');
            })
            ->where(function ($q) {
                $q->where('student_status', 'active')->orWhereNull('student_status');
            })
            ->where(function ($q) use ($assignedSectionIds) {
                $q->whereIn('section_id', $assignedSectionIds)
                  ->orWhereHas('level', function ($lq) use ($assignedSectionIds) {
                      $lq->whereIn('section_id', $assignedSectionIds);
                  });
            })
            ->count();

        // Classes in assigned section(s)
        $assignedClassIds = \App\Models\StudentClass::where('school_id', $auth->school_id)
            ->whereIn('section_id', $assignedSectionIds)
            ->pluck('id')
            ->toArray();

        // Teachers in assigned section(s)
        $teachersCount = User::where('school_id', $auth->school_id)
            ->where(function ($q) {
                $q->where('status', 1)->orWhereNull('status');
            })
            ->whereIn('id', function ($q) use ($assignedClassIds, $auth) {
                $q->select('user_id')
                  ->from('teacher_enrollments')
                  ->where('school_id', $auth->school_id)
                  ->where('enroll', '1')
                  ->whereIn('level_id', $assignedClassIds);
            })
            ->count();

        // Parents with children in assigned section(s)
        $parentsCount = User::where('school_id', $auth->school_id)
            ->whereRaw("LOWER(role) = 'parent'")
            ->whereIn('id', function ($q) use ($auth, $assignedSectionIds) {
                $q->select('parent_id')
                  ->from('parent_students')
                  ->whereIn('student_id', function ($sq) use ($auth, $assignedSectionIds) {
                      $sq->select('id')
                        ->from('users')
                        ->where('school_id', $auth->school_id)
                        ->where(function ($uq) use ($assignedSectionIds) {
                            $uq->whereIn('section_id', $assignedSectionIds)
                               ->orWhereIn('level_id', function ($lq) use ($assignedSectionIds) {
                                   $lq->select('id')->from('student_classes')->whereIn('section_id', $assignedSectionIds);
                               });
                        });
                  });
            })
            ->count();

        // Results uploaded for classes in assigned section(s)
        $resultsUploaded = Average::where('school_id', $auth->school_id)
            ->whereIn('class_id', $assignedClassIds)
            ->count();

        $students = $studentsCount;
        $teachers = $teachersCount;
        $parents  = $parentsCount;
        $graduates = 0;
        $withdrawn = 0;
        $totalHistoricalStudents = $studentsCount;
    } else {
        // === SCHOOL USERS COUNTS (Students, Teachers, Parents) IN ONE QUERY ===
        $schoolUsers = User::where('school_id', $auth->school_id)
            ->selectRaw("
                SUM(CASE WHEN LOWER(role) = 'student' AND (status = 1 OR status IS NULL) AND (student_status = 'active' OR student_status IS NULL) THEN 1 ELSE 0 END) as students,
                SUM(CASE WHEN LOWER(role) = 'teacher' AND (status = 1 OR status IS NULL) THEN 1 ELSE 0 END) as teachers,
                SUM(CASE WHEN LOWER(role) = 'parent' THEN 1 ELSE 0 END) as parents,
                SUM(CASE WHEN LOWER(role) = 'student' AND student_status = 'graduate' THEN 1 ELSE 0 END) as graduates,
                SUM(CASE WHEN LOWER(role) = 'student' AND student_status = 'withdrawn' THEN 1 ELSE 0 END) as withdrawn,
                SUM(CASE WHEN LOWER(role) = 'student' THEN 1 ELSE 0 END) as total_historical_students
            ")
            ->first();

        $students = (int) ($schoolUsers->students ?? 0);
        $teachers = (int) ($schoolUsers->teachers ?? 0);
        $parents  = (int) ($schoolUsers->parents ?? 0);
        $graduates = (int) ($schoolUsers->graduates ?? 0);
        $withdrawn = (int) ($schoolUsers->withdrawn ?? 0);
        $totalHistoricalStudents = (int) ($schoolUsers->total_historical_students ?? 0);
        $resultsUploaded = Average::where('school_id', $auth->school_id)->count();
    }

    // === SENIOR STUDENTS DEPARTMENT ATTENTION ===
    $deptAttentionService = app(\App\Services\Students\DepartmentAttentionService::class);
    $deptAttention = $deptAttentionService->getStudentsNeedingAttention((int) $auth->school_id, null, 15);

    // === RETURN ALL STATS ===
    return response()->json([
        'totalSchools'                 => $totalSchools,
        'totalAdmins'                  => $totalAdmins,
        'totalResults'                 => $totalResults,
        'students'                     => $students,
        'active_students'              => $students,
        'graduates_count'              => $graduates ?? 0,
        'withdrawn_count'              => $withdrawn ?? 0,
        'total_historical_students'    => $totalHistoricalStudents ?? $students,
        'teachers'                     => $teachers,
        'parents'                      => $parents,
        'results_uploaded'             => $resultsUploaded,
        'department_attention_count'   => (int) $deptAttention['total_count'],
        'department_attention_summary' => $deptAttention,
    ]);
}




public function getBursarStats()
{
    $auth = Auth::user();

    // Total assigned fees
    $totalAssigned = StudentFee::where('school_id', $auth->school_id)
        ->sum('total_amount');

    // Total paid
    $totalPaid = StudentFee::where('status', 'paid')
        ->where('school_id', $auth->school_id)
        ->sum('total_amount');

    // Total partially paid
    $totalPartiallyPaid = StudentFee::where('status', 'partial')
        ->where('school_id', $auth->school_id)
        ->sum('total_amount');

    // Total unpaid
    $totalUnpaid = StudentFee::where('status', 'unpaid')
        ->where('school_id', $auth->school_id)
        ->sum('total_amount');

    // Payment percentage
    $paymentPercentage = $totalAssigned > 0
        ? round(($totalPaid / $totalAssigned) * 100, 2)
        : 0;

    return response()->json([
        'totalAssigned' => $totalAssigned,
        'totalPaid' => $totalPaid,
        'totalPartiallyPaid' => $totalPartiallyPaid,
        'totalUnpaid' => $totalUnpaid,
        'paymentPercentage' => $paymentPercentage,
    ]);
}




public function schoolDomain()
{
   $user = Auth::user();

    // Make sure user belongs to a school
    if (!$user->school_id) {
        return response()->json([
            'message' => 'School not linked to user'
        ], 404);
    }

    $setting = SchoolSetting::where('id', $user->school_id)->first();

    if (!$setting || !$setting->school_subdomain) {
        return response()->json([
            'message' => 'School subdomain not set'
        ], 404);
    }

    return response()->json([
        'school_subdomain' => $setting->school_subdomain
    ], 200);
}


// PaymentStatusController.php
public function getPaymentStatus(Request $request)
{
  $user = Auth::user();
  $payment = Payment::where('school_id', $user->school_id)->latest()->first();

  return response()->json(['hasPayment' => $payment ? true : false]);
}

// SchoolSettingController.php
public function getSchoolSetting(Request $request)
{
  $user = Auth::user();
  $subdomain = SchoolSetting::where('user_id', $user->id)->first();

  return response()->json(['school_subdomain' => $subdomain->sub_domain]);
}




public function getPerformanceStats()
{
    $schoolId = Auth::user()->school_id;

    // 1️⃣ Get current session
    $currentSession = AcademicSession::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->where('is_current', 1)
        ->firstOrFail();

    $sessionName = $currentSession->name;

    // 2️⃣ Get all terms in this school
    $terms = Term::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->pluck('name');

    if ($terms->isEmpty()) {
        return response()->json([
            "error" => "No terms found for this school."
        ], 404);
    }

    // 3️⃣ Fetch all averages for the session in ONE query
    $averages = Average::where('school_id', $schoolId)
        ->where('session', $sessionName)
        ->whereIn('term', $terms)
        ->groupBy('term')
        ->selectRaw('term, AVG(total_average) as avg_score')
        ->pluck('avg_score', 'term'); // returns ['termName' => avg_score]

    // 4️⃣ Prepare result in order of terms
    $data = $terms->map(fn($term) => [
        'term' => $term,
        'average' => round($averages[$term] ?? 0, 2)
    ]);

    return response()->json([
        'session' => $sessionName,
        'data' => $data
    ]);
}








public function getTopPerformingStudents(Request $request)
{
    $user = Auth::user();
    $schoolId = $user->school_id;

    $limit = $request->input('limit', 5);
    $currentPage = $request->input('page', 1);

    // 🔹 Get active term and current session in ONE query each
    $activeTerm = Term::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->where('status', 'Active')
        ->first();

    $currentSession = AcademicSession::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->where('is_current', 1)
        ->first();

    if (!$activeTerm || !$currentSession) {
        return response()->json([
            'message' => 'Active term or current session not set'
        ], 400);
    }

    // 🔹 Fetch top performing students with only needed columns
    $paginated = Average::with([
            'student:id,firstname,surname,reg_no',
            'class:id,name'
        ])
        ->where('school_id', $schoolId)
        ->where('session', $currentSession->name)
        ->where('term', $activeTerm->name)
        ->orderByDesc('total_average')
        ->paginate($limit, ['*'], 'page', $currentPage);

    if ($paginated->total() === 0) {
        $students = User::with('level:id,name')
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->orderBy('surname')
            ->paginate($limit, ['id', 'firstname', 'surname', 'reg_no', 'level_id'], 'page', $currentPage);

        return response()->json([
            'data' => $students->map(fn ($student) => [
                'admission_no' => $student->reg_no ?? 'N/A',
                'name' => trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')),
                'class' => $student->level->name ?? 'N/A',
                'score' => 0,
            ])->values(),
            'total' => $students->total(),
            'session_used' => $currentSession->name,
            'term_used' => $activeTerm->name,
            'message' => 'No performance averages found yet. Showing registered students.',
        ]);
    }

    $results = $paginated->map(function ($item) {
        return [
            'admission_no' => $item->student->reg_no ?? 'N/A',
            'name' => trim(($item->student->firstname ?? '') . ' ' . ($item->student->surname ?? '')),
            'class' => $item->class->name ?? 'N/A',
            'score' => round($item->total_average ?? 0, 2),
        ];
    });

    return response()->json([
        'data' => $results,
        'total' => $paginated->total(),
        'session_used' => $currentSession->name,
        'term_used' => $activeTerm->name,
    ]);
}




 
// app/Http/Controllers/AcademicController.php

public function getCurrentSessionAndTerm()
{
    $schoolId = Auth::user()->school_id;

    // 🔹 Get active/current session (prioritizing explicitly marked current session)
    $session = AcademicSession::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->where('is_current', 1)
        ->select('name', 'start_date', 'end_date')
        ->first()
        ?: AcademicSession::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->where('status', 'Active')
            ->orderByDesc('id')
            ->select('name', 'start_date', 'end_date')
            ->first();

    // 🔹 Get active term
    $term = Term::where('school_id', $schoolId)
        ->whereNull('archived_at')
        ->where('status', 'Active')
        ->orderByRaw('COALESCE(sort_order, 999999) ASC')
        ->orderBy('id')
        ->select('name')
        ->first();

    $calendar = app(\App\Services\AcademicWeekService::class)->contextForSchool((int) $schoolId);

    return response()->json([
        'session' => $session?->name ?? 'Not Set',
        'start_date' => $session?->start_date ?? 'Not Set',
        'end_date' => $session?->end_date ?? 'Not Set',
        'term' => $term?->name ?? 'Not Set',
        'academic_calendar' => $calendar,
        'server_time' => now()->toIso8601String(),
    ]);
}




public function parentDetails(Request $request)
{
    $parent = Auth::user();

    // Ensure only a parent can access this
    if (!$parent || (!$parent->isParent() && strcasecmp((string) $parent->role, 'parent') !== 0)) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }


    // Fetch children linked to this parent (with student info)
    $children = \App\Models\ParentStudent::with([
        'student.section:id,name',
        'student.level:id,name'
    ])->where('parent_id', $parent->id)->get();

    if ($children->isEmpty()) {
        return response()->json([
            'message' => 'No children found for this parent',
            'children' => [],
            'childrenCount' => 0,
            'totalFeesPaid' => 0,
            'outstandingFees' => 0
        ]);
    }

    // Collect all student IDs
    $studentIds = $children->pluck('student_id');

    // Fetch all fees belonging to these students
    $fees = \App\Models\StudentFee::with([
        'feeType:id,name,amount',
        'session:id,name',
        'term:id,name'
    ])
        ->whereIn('student_id', $studentIds)
        ->where('school_id', $parent->school_id)
        ->get([
            'id',
            'student_id',
            'school_id',
            'section_id',
            'fee_type_id',
            'term_id',
            'session_id',
            'total_amount',
            'amount_paid',
            'balance',
        ]);

    // Calculate totals
    $totalFeesPaid = $fees->sum('amount_paid');
    $outstandingFees = $fees->sum('balance');

    // Structure children details neatly
$childrenDetails = $children->map(function ($child) use ($fees) {
    $studentFees = $fees->where('student_id', $child->student_id);
    $currentFee = $studentFees->first(); // pick the first unpaid/active fee

    return [
        'id' => $child->student->id,
        'school_id' => $child->student->school_id, 
        'name' => "{$child->student->firstname} {$child->student->surname}",
        'reg_no' => $child->student->reg_no,
        'section' => optional($child->student->section)->name ?? 'N/A',
        'class' => optional($child->student->level)->name ?? 'N/A',
        'total_fees' => $studentFees->sum('total_amount'),
        'amount_paid' => $studentFees->sum('amount_paid'), 
        'fee_id' => $currentFee?->id, 
        'balance' => $studentFees->sum('balance'),
    ];
});




    return response()->json([
        'childrenCount' => $childrenDetails->count(),
        'totalFeesPaid' => $totalFeesPaid,
        'outstandingFees' => $outstandingFees,
        'children' => $childrenDetails
    ]);

}

}
