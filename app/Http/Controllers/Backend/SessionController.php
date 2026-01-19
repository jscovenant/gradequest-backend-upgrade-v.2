<?php

namespace App\Http\Controllers\Backend;

use Illuminate\Http\Request;
use App\Models\AcademicSession;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SessionController extends Controller
{
    


    public function index()
    {
        $sessions = AcademicSession::where('school_id', Auth::user()->school_id)->get();
        return response()->json($sessions);
    }

    public function store(Request $request)
    {
       
        $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);
        
        $session = new AcademicSession();
        $session->name = $request->name;
        $session->school_id = Auth::user()->school_id;
        $session->start_date = $request->start_date;
        $session->end_date = $request->end_date;
         $session->status = 'Active';
        $session->save();
        
        
        return response()->json(['message' => 'Session created successfully'], 201);
    }

    public function destroy($id)
    {
        $session = AcademicSession::findOrFail($id);

        if ($session->school_id !== Auth::user()->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $session->delete();
        return response()->json(['message' => 'Session deleted successfully']);
    }


    public function show($id)
{
    $session = AcademicSession::where('school_id', Auth::user()->school_id)
                ->findOrFail($id);

    return response()->json($session);
}


    public function update(Request $request, $id)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
    ]);

    $session = AcademicSession::findOrFail($id);

    // Ensure session belongs to the authenticated user's school
    if ($session->school_id !== Auth::user()->school_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $session->name = $request->name;
    $session->start_date = $request->start_date;
    $session->end_date = $request->end_date;

    if ($request->has('status')) {
        $session->status = $request->status;
    }

    $session->save();

    return response()->json(['message' => 'Session updated successfully']);
}

public function setCurrent($id)
{
    $auth = auth()->user();

    // School-based scope
    AcademicSession::where('school_id', $auth->school_id)
        ->update(['is_current' => false]);

    AcademicSession::where('id', $id)
        ->where('school_id', $auth->school_id)
        ->update(['is_current' => true]);

    return response()->json([
        'message' => 'Current session updated successfully'
    ]);
}


}
