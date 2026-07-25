<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Services\SchoolBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TermController extends Controller
{
    /**
     * Display a listing of the terms for the authenticated school.
     */
    public function index()
    {
        $auth = Auth::user();

        $terms = Term::where('school_id', $auth->school_id)
                     ->latest()
                     ->get();

        return response()->json($terms);
    }

    /**
     * Store a newly created term in storage.
     */
    public function store(Request $request)
    {
        $auth = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Check for duplicate term name
        $exists = Term::where('school_id', $auth->school_id)
                      ->where('name', $request->name)
                      ->exists();

        if ($exists) {
            return response()->json(['message' => 'Term with this name already exists.'], 409);
        }

        $term = new Term();
        $term->name = $validated['name'];
        $term->start_date = $validated['start_date'] ?? null;
        $term->end_date = $validated['end_date'] ?? null;
        $term->school_id = $auth->school_id;
        $term->save();

        return response()->json([
            'message' => 'Term created successfully.',
            'term' => $term
        ], 201);
    }

    public function show($id)
    {
        $term = Term::findOrFail($id);
        return response()->json($term);
    }
    
    public function update(Request $request, $id)
    {
        $schoolId = Auth::user()->school_id;
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

    
        $term = Term::findOrFail($id);

        
        $term->update([
            'name' => $validated['name'],
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ]);
    
        return response()->json(['message' => 'Term updated successfully']);
    }
    
    public function destroy($id)
    {
        $auth = Auth::user();

        $term = Term::where('id', $id)
                    ->where('school_id', $auth->school_id)
                    ->first();

        if (!$term) {
            return response()->json(['message' => 'Term not found.'], 404);
        }

        $term->delete();

        return response()->json(['message' => 'Term deleted successfully.']);
    }

    public function updateStatus($id)
    {
        $term = Term::findOrFail($id);
        $schoolId = $term->school_id;

   
    
        // Deactivate all terms for this school first
        Term::where('school_id', $schoolId)->update(['status' => 'Inactive']);
    
        // Activate selected term
        $term->status = 'Active';
        $term->save();

        $session = AcademicSession::where('school_id', $schoolId)
            ->where('is_current', 1)
            ->orderByDesc('id')
            ->first()
            ?: AcademicSession::where('school_id', $schoolId)
                ->where('status', 'Active')
                ->orderByDesc('id')
                ->first();

        if ($session) {
            app(SchoolBillingService::class)->billingPeriodFor($schoolId, $session, $term, Auth::id(), 'term_activated');
        }
    
        return response()->json(['message' => 'Term set as active']);
    }




    
}
