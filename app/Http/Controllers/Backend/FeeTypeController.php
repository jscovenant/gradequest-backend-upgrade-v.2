<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\FeeType;
use App\Models\Term;
use App\Models\Section;
use App\Models\AcademicSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeeTypeController extends Controller
{





    public function index(Request $request)
    {
        $schoolId = Auth::user()->school_id;

        $query = FeeType::with(['section', 'session', 'term'])
            ->where('school_id', $schoolId);

        // 🔍 Search by fee name or amount
        if ($request->has('search') && $request->search !== '') {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('amount', 'like', '%' . $request->search . '%');
            });
        }

        // 🎯 Filter by section
        if ($request->filled('section_id')) {
            $query->where('section_id', $request->section_id);
        }

        // 🎯 Filter by session
        if ($request->filled('session_id')) {
            $query->where('session_id', $request->session_id);
        }

        // 🎯 Filter by term
        if ($request->filled('term_id')) {
            $query->where('term_id', $request->term_id);
        }

        // 📄 Paginate results
        $feeTypes = $query->orderBy('id', 'desc')->paginate(10);

        // 🎯 Also fetch related data dynamically
        $sections = Section::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->select('id', 'name')
            ->get();
        $sessions = AcademicSession::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->select('id', 'name')
            ->get();
        $terms = Term::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->select('id', 'name')
            ->get();

        // 🧾 Send all data together
        return response()->json([
            'feeTypes' => $feeTypes,
            'sections' => $sections,
            'sessions' => $sessions,
            'terms' => $terms,
        ]);
    }



    public function store(Request $request)
    {
        $schoolId = Auth::user()->school_id;

        $validated = $request->validate([
            'section_id' => 'required|exists:sections,id',
            'session_id' => 'required|exists:academic_sessions,id',
            'term_id' => 'required|exists:terms,id',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        if (!Section::where('school_id', $schoolId)->whereNull('archived_at')->where('id', $validated['section_id'])->exists()) {
            return response()->json(['message' => 'Selected section is archived or unavailable.'], 422);
        }

        $fee = FeeType::create([
            ...$validated,
            'school_id' => $schoolId,
        ]);

        return response()->json(['message' => 'Fee type created successfully', 'data' => $fee], 201);
    }

    public function show($id)
    {
        $schoolId = Auth::user()->school_id;
        $fee = FeeType::where('school_id', $schoolId)->findOrFail($id);
        return response()->json($fee);
    }

    public function update(Request $request, $id)
    {
        $schoolId = Auth::user()->school_id;
        $fee = FeeType::where('school_id', $schoolId)->findOrFail($id);

        $validated = $request->validate([
            'section_id' => 'required|exists:sections,id',
            'session_id' => 'required|exists:academic_sessions,id',
            'term_id' => 'required|exists:terms,id',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        if (!Section::where('school_id', $schoolId)->whereNull('archived_at')->where('id', $validated['section_id'])->exists()) {
            return response()->json(['message' => 'Selected section is archived or unavailable.'], 422);
        }

        $fee->update($validated);

        return response()->json(['message' => 'Fee type updated successfully', 'data' => $fee]);
    }

    public function destroy($id)
    {
        $schoolId = Auth::user()->school_id;
        $fee = FeeType::where('school_id', $schoolId)->findOrFail($id);
        $fee->delete();

        return response()->json(['message' => 'Fee type deleted successfully']);
    }
}
