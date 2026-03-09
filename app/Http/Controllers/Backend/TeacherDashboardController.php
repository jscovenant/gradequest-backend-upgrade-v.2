<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TeacherDashboardController extends Controller
{
   /**
     * GET /api/teacher/dashboard/counts
     */
    public function counts(Request $request)
    {
        $user = $request->user(); // teacher user
        $teacherId = $user->id;
        $schoolId  = $user->school_id;

        // Assigned classes (teacher_enrollments.level_id)
        $assignedClassIds = DB::table('teacher_enrollments')
            ->where('teacher_id', $teacherId)
            ->where('enroll', 1)
            ->pluck('level_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $assignedClasses = count($assignedClassIds);

        // Students in assigned classes (users.role='student', users.level_id in assigned)
        $students = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('role', 'student')
            ->when($assignedClasses > 0, fn ($q) => $q->whereIn('level_id', $assignedClassIds), fn ($q) => $q->whereRaw('1=0'))
            ->count();

        // Assigned subjects (teacher_subjects)
        $subjects = DB::table('teacher_subjects')
            ->where('teacher_id', $teacherId)
            ->where('enroll', 1)
            ->count();

        // Result batches created by this teacher
        $batchesTotal = DB::table('result_batches')
            ->where('school_id', $schoolId)
            ->where('created_by', $teacherId)
            ->count();

        $batchesSubmitted = DB::table('result_batches')
            ->where('school_id', $schoolId)
            ->where('created_by', $teacherId)
            ->whereIn('status', ['computed', 'approved', 'published'])
            ->count();

        // Completion percentage (submitted / total)
        $completion = $batchesTotal > 0
            ? round(($batchesSubmitted / $batchesTotal) * 100) . '%'
            : '0%';

        return response()->json([
            'students' => $students,
            'classes'  => $assignedClasses,
            'subjects' => $subjects,
            'results_uploaded' => $completion,
        ]);
    }

    /**
     * GET /api/teacher/performance-stats
     * Bar chart: average class performance by term (based on student_results_v2.total_average)
     */
    public function performanceStats(Request $request)
    {
        $user = $request->user();
        $teacherId = $user->id;
        $schoolId  = $user->school_id;

        // Join student_results_v2 -> result_batches, filter batches created_by teacher
        $rows = DB::table('student_results_v2 as sr')
            ->join('result_batches as b', 'b.id', '=', 'sr.batch_id')
            ->selectRaw('b.term as term, AVG(COALESCE(NULLIF(sr.total_average, ""), 0)) as average')
            ->where('b.school_id', $schoolId)
            ->where('b.created_by', $teacherId)
            ->groupBy('b.term')
            ->orderByRaw("FIELD(b.term,'First Term','Second Term','Third Term')") // optional nice ordering
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    /**
     * GET /api/teacher/access-stats
     * Line chart: results activity for last 5 weekdays (how many student results saved)
     */
    public function accessStats(Request $request)
    {
        $user = $request->user();
        $teacherId = $user->id;
        $schoolId  = $user->school_id;

        $start = Carbon::now()->subDays(14)->startOfDay(); // look back 2 weeks

        $rows = DB::table('student_results_v2 as sr')
            ->join('result_batches as b', 'b.id', '=', 'sr.batch_id')
            ->selectRaw('DATE(sr.created_at) as day, COUNT(*) as cnt')
            ->where('b.school_id', $schoolId)
            ->where('b.created_by', $teacherId)
            ->where('sr.created_at', '>=', $start)
            ->groupBy('day')
            ->orderBy('day', 'asc')
            ->get();

        // Build last 5 weekdays labels (Mon..Fri style)
        $labels = [];
        $data = [];

        // Take last 5 distinct days from rows (fallback to empty)
        $last = $rows->take(-5);

        foreach ($last as $r) {
            $labels[] = Carbon::parse($r->day)->format('D'); // Mon, Tue...
            $data[] = (int) $r->cnt;
        }

        // If no data, return default Mon-Fri zeros
        if (count($labels) === 0) {
            $labels = ["Mon","Tue","Wed","Thu","Fri"];
            $data = [0,0,0,0,0];
        }

        return response()->json([
            'labels' => $labels,
            'data' => $data,
        ]);
    } 
}
