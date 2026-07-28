<?php

namespace App\Http\Controllers\Backend;

use Illuminate\Http\Request;
use App\Models\AcademicSession;
use App\Http\Controllers\Controller;
use App\Services\AcademicSetupArchiveService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SessionController extends Controller
{
    


    public function index(Request $request)
    {
        $sessions = AcademicSession::where('school_id', Auth::user()->school_id)
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'))
            ->when(!$request->boolean('archived') && !$request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->get();
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
        $session = AcademicSession::where('school_id', Auth::user()->school_id)->findOrFail($id);

        if ($session->school_id !== Auth::user()->school_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $usedInResults = app(AcademicSetupArchiveService::class)->sessionHasResultRecords($session);

        $session->forceFill([
            'archived_at' => now(),
            'is_current' => false,
            'status' => 'Inactive',
        ])->save();

        return response()->json([
            'message' => $usedInResults
                ? 'This academic session is already used in results. It has been archived instead and will no longer appear for future result entry.'
                : 'Academic session archived successfully.',
            'archived' => true,
            'used_in_results' => $usedInResults,
        ]);
    }

    public function restore($id)
    {
        $schoolId = Auth::user()->school_id;

        $session = AcademicSession::where('school_id', $schoolId)
            ->whereNotNull('archived_at')
            ->find($id);

        if (!$session) {
            return response()->json(['message' => 'Archived academic session not found.'], 404);
        }

        $exists = AcademicSession::where('school_id', $schoolId)
            ->where('name', $session->name)
            ->where('id', '!=', $session->id)
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'An active academic session with this name already exists. Rename it before restoring.'], 422);
        }

        $session->forceFill(['archived_at' => null])->save();

        return response()->json([
            'message' => 'Academic session restored successfully.',
            'archived' => false,
        ]);
    }


    public function show($id)
{
    $session = AcademicSession::where('school_id', Auth::user()->school_id)
                ->whereNull('archived_at')
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

    $session = AcademicSession::whereNull('archived_at')->findOrFail($id);

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
    $auth = auth::user();

    // School-based scope
    AcademicSession::where('school_id', $auth->school_id)
        ->whereNull('archived_at')
        ->update(['is_current' => false]);

    AcademicSession::where('id', $id)
        ->where('school_id', $auth->school_id)
        ->whereNull('archived_at')
        ->update(['is_current' => true]);

    return response()->json([
        'message' => 'Current session updated successfully'
    ]);
}


}
