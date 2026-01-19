<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TeacherSubject;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\Auth;


class TeacherSubjectController extends Controller
{
    public function index()
    {
        $assignments = TeacherSubject::with(['teacher', 'subject'])->get();
        return response()->json($assignments);
    }

    public function store(Request $request)
    {
        $request->validate([
            'teacher_id' => 'required|exists:users,id',
            'subject_ids' => 'required|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        foreach ($request->subject_ids as $subjectId) {
            TeacherSubject::updateOrCreate([
                'teacher_id' => $request->teacher_id,
                'subject_id' => $subjectId
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Subjects assigned successfully']);
    }

    public function allTeachers()
    {
         $school_id = Auth::user()->school_id; 
        $teachers = User::where('role', 'teacher')
            ->select('id', 'firstname', 'surname')
            ->where('school_id', $school_id)
            ->get();

        return response()->json($teachers);
    }

 public function allSubjects()
{
    $school_id = Auth::user()->school_id;

    $subjects = Subject::where('school_id', $school_id)
        ->selectRaw('MIN(id) as id, name')
        ->groupBy('name')
        ->orderBy('name')
        ->get();

    return response()->json($subjects);
}


 public function destroy($teacher_id, $subject_id)
{
    $assignment = TeacherSubject::where('teacher_id', $teacher_id)
        ->where('subject_id', $subject_id)
        ->first();

    if (!$assignment) {
        return response()->json(['message' => 'Assignment not found'], 404);
    }

    $assignment->delete();

    return response()->json(['message' => 'Subject removed from teacher']);
}

}
