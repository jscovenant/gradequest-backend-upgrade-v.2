<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class TeacherDashboardController extends Controller
{
    private function assignedClassIds(int $teacherId, int $schoolId): array
    {
        $query = DB::table('teacher_enrollments')
            ->where('enroll', 1);

        if (Schema::hasColumn('teacher_enrollments', 'school_id')) {
            $query->whereIn('school_id', [$schoolId, 0]);
        }

        $query->where(function ($q) use ($teacherId) {
            if (Schema::hasColumn('teacher_enrollments', 'teacher_id')) {
                $q->where('teacher_id', $teacherId)
                    ->orWhere('user_id', $teacherId);

                return;
            }

            $q->where('user_id', $teacherId);
        });

        return $query->pluck('level_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

   /**
     * GET /api/teacher/dashboard/counts
     */
    public function counts(Request $request)
    {
        $user = $request->user(); // teacher user
        $teacherId = $user->id;
        $schoolId  = $user->school_id;

        // Assigned classes (teacher_enrollments.level_id)
        $assignedClassIds = $this->assignedClassIds($teacherId, $schoolId);

        $assignedClasses = count($assignedClassIds);

        // Students in assigned classes (users.role='student', users.level_id in assigned)
        $students = DB::table('users')
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->when($assignedClasses > 0, fn ($q) => $q->whereIn('level_id', $assignedClassIds), fn ($q) => $q->whereRaw('1=0'))
            ->count();

        // Assigned subjects (teacher_subjects)
        $subjects = DB::table('teacher_subjects')
            ->where('teacher_id', $teacherId)

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
        $assignedClassIds = $this->assignedClassIds($teacherId, $schoolId);

        // Join student_results_v2 -> result_batches, filter batches created_by teacher
        $rows = DB::table('student_results_v2 as sr')
            ->join('result_batches as b', 'b.id', '=', 'sr.batch_id')
            ->selectRaw('b.term as term, AVG(COALESCE(NULLIF(sr.total_average, ""), 0)) as average')
            ->where('b.school_id', $schoolId)
            ->when(! empty($assignedClassIds), fn ($q) => $q->whereIn('b.class_id', $assignedClassIds), fn ($q) => $q->whereRaw('1=0'))
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

    /**
     * GET /api/teacher/action-center
     * Practical work summary for the teacher dashboard.
     */
    public function actionCenter(Request $request)
    {
        $user = $request->user();
        $teacherId = (int) $user->id;
        $schoolId = (int) $user->school_id;
        $today = Carbon::today()->toDateString();
        $assignedClassIds = $this->assignedClassIds($teacherId, $schoolId);

        if (empty($assignedClassIds)) {
            return response()->json([
                'actions' => [[
                    'priority' => 'high',
                    'label' => 'No class assigned',
                    'description' => 'Ask the school admin to assign you to a class before using teacher tools.',
                    'route' => '/my-classes',
                    'icon' => 'building-exclamation',
                ]],
                'attendance' => [
                    'date' => $today,
                    'total_students' => 0,
                    'marked_today' => 0,
                    'present_today' => 0,
                    'absent_today' => 0,
                    'late_today' => 0,
                    'attendance_rate' => 0,
                    'classes_needing_attendance' => [],
                    'frequent_absentees' => [],
                ],
                'results' => [
                    'total_batches' => 0,
                    'completed_batches' => 0,
                    'pending_batches_count' => 0,
                    'completion_percent' => 0,
                    'pending_batches' => [],
                ],
            ]);
        }

        $classes = DB::table('student_classes')
            ->where('school_id', $schoolId)
            ->whereIn('id', $assignedClassIds)
            ->select('id', 'name')
            ->get()
            ->keyBy('id');

        $students = DB::table('users')
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->whereIn('level_id', $assignedClassIds)
            ->select('id', 'firstname', 'surname', 'reg_no', 'level_id')
            ->get();

        $studentIds = $students->pluck('id')->all();
        $studentsByClass = $students->groupBy('level_id');

        $todayAttendance = empty($studentIds)
            ? collect()
            : DB::table('attendances')
                ->where('school_id', $schoolId)
                ->whereDate('date', $today)
                ->whereIn('student_id', $studentIds)
                ->select('student_id', 'class_id', 'status')
                ->get();

        $markedToday = $todayAttendance->pluck('student_id')->unique()->count();
        $presentToday = $todayAttendance->where('status', 'present')->count();
        $lateToday = $todayAttendance->where('status', 'late')->count();
        $absentToday = $todayAttendance->whereIn('status', ['absent', 'excused'])->count();

        $classesNeedingAttendance = $classes->map(function ($class) use ($studentsByClass, $todayAttendance) {
            $total = $studentsByClass->get($class->id, collect())->count();
            $marked = $todayAttendance->where('class_id', $class->id)->pluck('student_id')->unique()->count();

            return [
                'class_id' => (int) $class->id,
                'class_name' => $class->name,
                'total_students' => $total,
                'marked_students' => $marked,
                'missing_count' => max(0, $total - $marked),
            ];
        })->filter(fn ($class) => $class['total_students'] > 0 && $class['missing_count'] > 0)
            ->values();

        $start30 = Carbon::today()->subDays(30)->toDateString();
        $absenceRows = empty($studentIds)
            ? collect()
            : DB::table('attendances')
                ->where('school_id', $schoolId)
                ->whereBetween('date', [$start30, $today])
                ->whereIn('student_id', $studentIds)
                ->whereIn('status', ['absent', 'excused'])
                ->select('student_id', DB::raw('COUNT(*) as absences'))
                ->groupBy('student_id')
                ->orderByDesc('absences')
                ->limit(5)
                ->get()
                ->keyBy('student_id');

        $studentsById = $students->keyBy('id');
        $frequentAbsentees = $absenceRows->map(function ($row) use ($studentsById, $classes) {
            $student = $studentsById->get($row->student_id);

            return [
                'id' => (int) $row->student_id,
                'name' => $student ? trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')) : 'Student',
                'reg_no' => $student->reg_no ?? null,
                'class_name' => $student ? ($classes->get($student->level_id)->name ?? null) : null,
                'absences' => (int) $row->absences,
            ];
        })->values();

        $batches = DB::table('result_batches as b')
            ->leftJoin('student_classes as c', 'c.id', '=', 'b.class_id')
            ->where('b.school_id', $schoolId)
            ->whereIn('b.class_id', $assignedClassIds)
            ->select('b.id', 'b.class_id', 'b.term', 'b.session', 'b.status', 'c.name as class_name')
            ->orderByDesc('b.created_at')
            ->get();

        $resultCounts = $batches->isEmpty()
            ? collect()
            : DB::table('student_results_v2')
                ->whereIn('batch_id', $batches->pluck('id')->all())
                ->select('batch_id', DB::raw('COUNT(*) as entered_count'))
                ->groupBy('batch_id')
                ->get()
                ->keyBy('batch_id');

        $pendingBatches = $batches->filter(fn ($batch) => ! in_array($batch->status, ['computed', 'approved', 'published'], true))
            ->map(function ($batch) use ($studentsByClass, $resultCounts) {
                $expected = $studentsByClass->get($batch->class_id, collect())->count();
                $entered = (int) ($resultCounts->get($batch->id)->entered_count ?? 0);

                return [
                    'id' => (int) $batch->id,
                    'class_name' => $batch->class_name,
                    'term' => $batch->term,
                    'session' => $batch->session,
                    'status' => $batch->status,
                    'entered_count' => $entered,
                    'expected_count' => $expected,
                    'missing_count' => max(0, $expected - $entered),
                ];
            })->take(5)->values();

        $completedBatches = $batches->whereIn('status', ['computed', 'approved', 'published'])->count();
        $pendingCount = max(0, $batches->count() - $completedBatches);

        $actions = [];

        if ($classesNeedingAttendance->isNotEmpty()) {
            $actions[] = [
                'priority' => 'high',
                'label' => 'Take today\'s attendance',
                'description' => $classesNeedingAttendance->count() . ' assigned class(es) still have unmarked students.',
                'route' => '/students/attendance',
                'icon' => 'clipboard-check',
            ];
        }

        if ($pendingCount > 0) {
            $actions[] = [
                'priority' => 'medium',
                'label' => 'Complete pending results',
                'description' => "{$pendingCount} result batch(es) still need entry or computation.",
                'route' => '/results/upload',
                'icon' => 'file-earmark-text',
            ];
        }

        if ($frequentAbsentees->isNotEmpty()) {
            $actions[] = [
                'priority' => 'medium',
                'label' => 'Follow up absentees',
                'description' => $frequentAbsentees->count() . ' student(s) have repeated absence in the last 30 days.',
                'route' => '/students/attendance',
                'icon' => 'person-exclamation',
            ];
        }

        if (empty($actions)) {
            $actions[] = [
                'priority' => 'low',
                'label' => 'All clear for now',
                'description' => 'Attendance and result tasks look up to date from available records.',
                'route' => '/my-classes',
                'icon' => 'check2-circle',
            ];
        }

        return response()->json([
            'actions' => $actions,
            'attendance' => [
                'date' => $today,
                'total_students' => $students->count(),
                'marked_today' => $markedToday,
                'present_today' => $presentToday,
                'absent_today' => $absentToday,
                'late_today' => $lateToday,
                'attendance_rate' => $students->count() ? round((($presentToday + $lateToday) / $students->count()) * 100, 1) : 0,
                'classes_needing_attendance' => $classesNeedingAttendance,
                'frequent_absentees' => $frequentAbsentees,
            ],
            'results' => [
                'total_batches' => $batches->count(),
                'completed_batches' => $completedBatches,
                'pending_batches_count' => $pendingCount,
                'completion_percent' => $batches->count() ? round(($completedBatches / $batches->count()) * 100, 1) : 0,
                'pending_batches' => $pendingBatches,
            ],
        ]);
    }

    /**
     * GET /api/teacher/student-performance
     * Returns students in the teacher's assigned classes, split into strong
     * and struggling groups, with trend data and practical intervention hints.
     */
    public function studentPerformance(Request $request)
    {
        $user = $request->user();
        $teacherId = (int) $user->id;
        $schoolId = (int) $user->school_id;
        $assignedClassIds = $this->assignedClassIds($teacherId, $schoolId);

        if (empty($assignedClassIds)) {
            return response()->json([
                'summary' => [
                    'tracked_students' => 0,
                    'strong_count' => 0,
                    'struggling_count' => 0,
                    'class_average' => 0,
                ],
                'top_performers' => [],
                'struggling_students' => [],
            ]);
        }

        $students = DB::table('users as u')
            ->leftJoin('student_classes as c', 'c.id', '=', 'u.level_id')
            ->where('u.school_id', $schoolId)
            ->whereRaw('LOWER(u.role) = ?', ['student'])
            ->whereIn('u.level_id', $assignedClassIds)
            ->where('u.status', 1)
            ->select([
                'u.id',
                'u.firstname',
                'u.surname',
                'u.reg_no',
                'u.level_id',
                'c.name as class_name',
            ])
            ->orderBy('u.surname')
            ->orderBy('u.firstname')
            ->get();

        $studentIds = $students->pluck('id')->all();

        if (empty($studentIds)) {
            return response()->json([
                'summary' => [
                    'tracked_students' => 0,
                    'strong_count' => 0,
                    'struggling_count' => 0,
                    'class_average' => 0,
                ],
                'top_performers' => [],
                'struggling_students' => [],
            ]);
        }

        $resultRows = DB::table('student_results_v2 as sr')
            ->join('result_batches as b', 'b.id', '=', 'sr.batch_id')
            ->where('b.school_id', $schoolId)
            ->whereIn('b.class_id', $assignedClassIds)
            ->whereIn('sr.user_id', $studentIds)
            ->select([
                'sr.id',
                'sr.user_id',
                'sr.total_average',
                'sr.total_grade',
                'b.term',
                'b.session',
                'b.class_id',
                'sr.updated_at',
            ])
            ->orderBy('sr.user_id')
            ->orderBy('b.session')
            ->orderByRaw("FIELD(b.term,'First Term','Second Term','Third Term')")
            ->get()
            ->groupBy('user_id');

        $resultIds = $resultRows->flatten(1)->pluck('id')->all();

        $weakSubjects = empty($resultIds)
            ? collect()
            : DB::table('subject_results_v2 as subr')
                ->leftJoin('subjects as s', 's.id', '=', 'subr.subject_id')
                ->whereIn('subr.student_result_id', $resultIds)
                ->select([
                    'subr.student_result_id',
                    's.name as subject_name',
                    DB::raw('CAST(COALESCE(NULLIF(subr.total, ""), 0) AS DECIMAL(10,2)) as score'),
                ])
                ->orderBy('score')
                ->get()
                ->groupBy('student_result_id');

        $items = $students->map(function ($student) use ($resultRows, $weakSubjects) {
            $rows = $resultRows->get($student->id, collect())->values();

            $scores = $rows
                ->map(fn ($row) => (float) ($row->total_average ?: 0))
                ->filter(fn ($score) => $score > 0)
                ->values();

            $hasResults = $scores->isNotEmpty();
            $average = $scores->isNotEmpty() ? round($scores->avg(), 1) : 0;
            $latest = $scores->last() ?? 0;
            $previous = $scores->count() >= 2 ? $scores[$scores->count() - 2] : null;
            $change = $previous !== null ? round($latest - $previous, 1) : 0;
            $status = ! $hasResults ? 'no_results' : ($average >= 70 ? 'strong' : ($average < 50 ? 'struggling' : 'watch'));

            $trend = $rows->take(-6)->map(function ($row) {
                return [
                    'label' => trim(($row->term ?? '') . ' ' . ($row->session ?? '')),
                    'score' => round((float) ($row->total_average ?: 0), 1),
                ];
            })->values();

            $latestResultId = $rows->last()?->id;
            $weak = $latestResultId
                ? $weakSubjects->get($latestResultId, collect())
                    ->take(3)
                    ->map(fn ($row) => [
                        'subject' => $row->subject_name ?? 'Subject',
                        'score' => round((float) $row->score, 1),
                    ])
                    ->values()
                : collect();

            return [
                'id' => (int) $student->id,
                'name' => trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')) ?: 'Student',
                'reg_no' => $student->reg_no,
                'class_name' => $student->class_name,
                'average' => $average,
                'latest_score' => round((float) $latest, 1),
                'change' => $change,
                'status' => $status,
                'trend' => $trend,
                'weak_subjects' => $weak,
                'insight' => $this->studentInsight($average, $change, $weak, $hasResults),
            ];
        })->values();

        $withResults = $items->filter(fn ($item) => $item['status'] !== 'no_results')->values();

        $top = $withResults->sortByDesc('average')->take(5)->values();
        $struggling = $items
            ->filter(fn ($item) => $item['status'] === 'struggling' || $item['status'] === 'no_results' || $item['change'] <= -10)
            ->sortBy('average')
            ->take(5)
            ->values();

        return response()->json([
            'summary' => [
                'tracked_students' => $items->count(),
                'strong_count' => $items->where('status', 'strong')->count(),
                'struggling_count' => $items->where('status', 'struggling')->count(),
                'class_average' => $withResults->count() ? round($withResults->avg('average'), 1) : 0,
            ],
            'top_performers' => $top,
            'struggling_students' => $struggling,
        ]);
    }

    private function studentInsight(float $average, float $change, $weakSubjects, bool $hasResults = true): array
    {
        $weakNames = collect($weakSubjects)->pluck('subject')->filter()->take(2)->values()->all();
        $subjectText = count($weakNames) ? implode(' and ', $weakNames) : 'recent weak areas';

        if (! $hasResults) {
            return [
                'level' => 'no_results',
                'headline' => 'No result recorded yet',
                'recommendation' => 'Enter or compute this student result first, then the dashboard will start showing performance guidance.',
            ];
        }

        if ($average < 40) {
            return [
                'level' => 'urgent',
                'headline' => 'Needs immediate support',
                'recommendation' => "Schedule a short one-on-one review, contact the parent, and give focused practice on {$subjectText}.",
            ];
        }

        if ($average < 50) {
            return [
                'level' => 'at_risk',
                'headline' => 'At risk of falling behind',
                'recommendation' => "Pair the student with a stronger peer, review {$subjectText}, and check progress again after the next assessment.",
            ];
        }

        if ($change <= -10) {
            return [
                'level' => 'declining',
                'headline' => 'Performance is dropping',
                'recommendation' => 'Compare recent classwork with earlier performance and check attendance, confidence, or topic difficulty before the next lesson.',
            ];
        }

        if ($average >= 75) {
            return [
                'level' => 'excellent',
                'headline' => 'Doing very well',
                'recommendation' => 'Offer extension work, leadership tasks, or competition-style questions to keep the student challenged.',
            ];
        }

        return [
            'level' => 'stable',
            'headline' => 'Performance is steady',
            'recommendation' => 'Keep monitoring the student and use short feedback after each assessment to push gradual improvement.',
        ];
    }
}
