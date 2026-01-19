<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Attendance;
use App\Models\StudentClass;
use Illuminate\Support\Facades\Auth;


class AttendanceController extends Controller
{
    
/**
     * Fetch students by class + their attendance for a given date
     */
    public function index(Request $request)
    {
        $request->validate([
            'class_id' => 'required|exists:student_classes,id',
            'date'     => 'nullable|date',
        ]);

        $classId = $request->query('class_id');
        $date    = $request->query('date') ?? now()->toDateString();

        $students = User::with(['level', 'attendances' => function ($q) use ($date) {
                $q->whereDate('date', $date);
            }])
            ->where('role', 'Student')
            ->where('level_id', $classId)
            ->get();

        return response()->json($students);
    }

    /**
     * Save/update attendance for a student
     */
   public function store(Request $request)
{
    $validated = $request->validate([
        'class_id' => 'required|exists:student_classes,id',
        'school_id' => 'required|exists:school_settings,id',
        'date' => 'required|date',
        'students' => 'required|array',
        'students.*.student_id' => 'required|exists:users,id',
        'students.*.status' => 'required|string|in:present,absent,late,excused',
        'students.*.remarks' => 'nullable|string',
    ]);

    foreach ($validated['students'] as $stu) {
        Attendance::updateOrCreate(
            [
                'student_id' => $stu['student_id'],
                'date' => $validated['date'],
            ],
            [
                'class_id' => $validated['class_id'],
                'school_id' => $validated['school_id'],
                'status' => $stu['status'],
                'remarks' => $stu['remarks'] ?? null,
            ]
        );
    }

    return response()->json(['message' => 'Attendance saved successfully']);
}

public function report(Request $request)
{
    $validated = $request->validate([
        'class_id' => 'required|exists:student_classes,id',
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
    ]);

    $students = User::where('role', 'Student')->where('level_id', $validated['class_id'])
        ->with(['attendances' => function ($q) use ($validated) {
            $q->whereBetween('date', [$validated['start_date'], $validated['end_date']]);
        }])
        ->get();

    $report = $students->map(function ($stu) {
        return [
            'id' => $stu->id,
            'name' => $stu->surname . ' ' . $stu->firstname,
            'present' => $stu->attendances->where('status', 'present')->count(),
            'absent' => $stu->attendances->where('status', 'absent')->count(),
            'late' => $stu->attendances->where('status', 'late')->count(),
            'excused' => $stu->attendances->where('status', 'excused')->count(),
            'total' => $stu->attendances->count(),
        ];
    });

    return response()->json($report);
}


  public function getClassForStudentReport()
    {
        $schoolId = Auth::user()->school_id;
        return response()->json(StudentClass::where('school_id',  $schoolId )->get());
    }

}
