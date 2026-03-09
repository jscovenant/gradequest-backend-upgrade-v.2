<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\StudentClass;
use App\Models\Term;
use App\Models\AcademicSession; // or Session (adjust if needed)
use App\Models\Department;
use App\Models\Section;
use App\Models\TeacherEnrollment;

class FilterSetupController extends Controller
{
    /**
     * Get student classes for logged-in school
     */
     
  
public function studentClasses(Request $request)
{
    $user = $request->user();
    $schoolId = $user->school_id;

    $query = StudentClass::where('school_id', $schoolId)->orderBy('name');

    // ✅ If Teacher: only classes assigned to the teacher
    if ($user->role === 'Teacher') {
        $enrolledLevelIds = TeacherEnrollment::where('school_id', $schoolId)
            ->where('user_id', $user->id)
            ->where('enroll', '1')
            ->pluck('level_id')
            ->toArray();

        $query->whereIn('id', $enrolledLevelIds);
    }

    $classes = $query->get(['id', 'name']);

    // ✅ return role too (frontend can show a helpful hint)
    return response()->json([
        'classes' => $classes,
        'user_role' => $user->role,
    ]);
}

    /**
     * Get terms (can be school specific or global)
     */
    public function terms(Request $request)
    {
        $schoolId = $request->user()->school_id;

        $terms = Term::where('school_id', $schoolId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($terms);
    }

    /**
     * Get academic sessions
     */
    public function academicSessions(Request $request)
    {
        $schoolId = $request->user()->school_id;

        $sessions = AcademicSession::where('school_id', $schoolId)
            ->orderByDesc('id')
            ->get(['id', 'name']); // if column is "session", change to ->get(['id','session as name'])

        return response()->json($sessions);
    }

    /**
     * Optional: departments
     */
    public function departments(Request $request)
    {
        $schoolId = $request->user()->school_id;

        $departments = Department::where('school_id', $schoolId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($departments);
    }

    /**
     * Optional: sections
     */
    public function sections(Request $request)
    {
        $schoolId = $request->user()->school_id;

        $sections = Section::where('school_id', $schoolId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($sections);
    }
}
