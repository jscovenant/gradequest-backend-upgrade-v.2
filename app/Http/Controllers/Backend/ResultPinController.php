<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ResultPin;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ResultPinController extends Controller
{
    // LIST PINS
    public function index()
    {
       return ResultPin::where('school_id', auth()->user()->school_id)
        ->latest()
        ->get();
    }
    
    // Get all terms for the current school
public function getTerms()
{
    $schoolId = auth()->user()->school_id;

    $terms = DB::table('terms')
        ->where('school_id', $schoolId)
        ->where('status', 'Active')
        ->get(['id', 'name']);

    return response()->json($terms);
}

// Get all academic sessions for the current school
public function getSessions()
{
    $schoolId = auth()->user()->school_id;

    $sessions = DB::table('academic_sessions')
        ->where('school_id', $schoolId)
        ->where('is_current', 1) 
        ->get(['id', 'name']);

    return response()->json($sessions);
}

    // CREATE PIN (HASHED)

public function store(Request $request)
{
    $request->validate([
        'term' => 'required|string',
        'session' => 'required|string',
        'max_uses' => 'required|integer|min:1|max:10',
        'expires_at' => 'nullable|date|after:today',
        'quantity' => 'nullable|integer|min:1|max:100',
    ]);

   $schoolId = auth()->user()->school_id;
    $quantity = (int) $request->quantity;

    // 🚫 Hard limit check (extra safety)
    if ($quantity > 100) {
        return response()->json([
            'message' => 'You can only generate a maximum of 100 PINs at a time. Please generate another batch after.',
        ], 422);
    }

    // Check for existing pins
    $existingPins = ResultPin::where('school_id', $schoolId)->get();

    if ($existingPins->isNotEmpty()) {
        if ($existingPins->first()->session !== $request->session) {
            ResultPin::where('school_id', $schoolId)->delete();
        }
    }

    $generatedPins = [];

    for ($i = 0; $i < $quantity; $i++) {

        // Ensure PIN uniqueness
        do {
            $plainPin = str_pad(random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        } while (
            ResultPin::where('pin', $plainPin)->exists()
        );

        // Insert ONE AT A TIME
        ResultPin::create([
            'school_id'  => $schoolId,
            'pin'        => $plainPin,
            'term'       => $request->term,
            'session'    => $request->session,
            'max_uses'   => $request->max_uses,
            'expires_at'=> $request->expires_at,
        ]);

        $generatedPins[] = $plainPin;
    }

    return response()->json([
        'message' => "{$quantity} PIN(s) generated successfully. You may generate another batch if needed.",
        'pins'    => $generatedPins,
    ], 201);
}
    }