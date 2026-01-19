<?php

namespace App\Http\Controllers\Backend;

use App\Models\Section;
use App\Models\Subject;
use App\Models\Department;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Level;
use Illuminate\Support\Facades\Auth;

class SubjectController extends Controller
{
    //

 // Get all subjects for a department
 public function getAllSubjects($departmentId)
 {
     $subjects = Subject::where('department_id', $departmentId)->get();

     return response()->json($subjects);
 }
 
 
 // Fetch all sections for the logged-in user's school
public function getSections()
{
    $schoolId = Auth::user()->school_id;

    $sections = Section::where('school_id', $schoolId)->get();

    return response()->json($sections);
}

 
 // Assign or remove section to multiple subjects
public function assignSection(Request $request)
{
    $request->validate([
        'section_id' => 'required|exists:sections,id',
        'subject_ids' => 'required|array',
        'subject_ids.*' => 'exists:subjects,id'
    ]);

    Subject::whereIn('id', $request->subject_ids)
        ->update(['section_id' => $request->section_id]);

    return response()->json(['message' => 'Subjects successfully assigned to section']);
}


 // Store a new subject for a department
 public function storeSubject(Request $request, $departmentId)
 {
     $request->validate([
         'name' => 'required|string|max:255',
     ]);
 
     $department = Department::find($departmentId);
 
     if (!$department) {
         return response()->json(['message' => 'Department not found'], 404);
     }
 
     $schoolId = Auth::user()->school_id;
 
     // Check for duplicate subject name in the same department and school
     $existingSubject = Subject::where('name', $request->name)
         ->where('department_id', $department->id)
         ->where('school_id', $schoolId)
         ->first();
 
     if ($existingSubject) {
         return response()->json([
             'message' => 'Subject with this name already exists in the selected department.'
         ], 422);
     }
 
     // Generate subject code
     $prefix = strtoupper(substr($department->name, 0, 3)); // e.g., "SCI" for Science
     $subjectCount = Subject::where('department_id', $department->id)->count();
     $subjectNumber = str_pad($subjectCount + 1, 3, '0', STR_PAD_LEFT);
     $subjectCode = $prefix . $subjectNumber;
 
     $subject = new Subject();
     $subject->name = $request->name;
     $subject->subject_id = $subjectCode;
     $subject->department_id = $department->id;
     $subject->school_id = $schoolId;
     $subject->save();
 
     return response()->json([
         'message' => 'Subject added successfully',
         'subject_code' => $subjectCode
     ], 201);
 }
 


// Show a specific subject
            public function edit($id)
            {
            $subject = Subject::findOrFail($id);
            return response()->json($subject);
            }

            // Update subject
            public function update(Request $request, $id)
            {


            $request->validate([
            'name' => 'required|string|max:255'
            ]);

            $schoolId = Auth::user()->school_id;
            $exists = Subject::where('name', $request->name)
            ->where('school_id', $schoolId)
            ->where('id', '!=', $id)
            ->exists();

          

            $subject = Subject::findOrFail($id);
            $subject->name = $request->name;
            $subject->save();

            return response()->json(['message' => 'Subject updated successfully']);
            }




 // Delete a subject
 public function destroy($id)
 {
     $subject = Subject::find($id);

     if (!$subject) {
         return response()->json(['message' => 'Subject not found'], 404);
     }

     $subject->delete();

     return response()->json(['message' => 'Subject deleted successfully']);
 }
}
