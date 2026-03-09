<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\BiometricId;
use App\Models\QrCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class BiometricQRController extends Controller
{
    // Search staff by reg_no
    public function findStaff(Request $request)
    {
        $schoolId = Auth::user()->school_id;
        $request->validate(['reg_no' => 'required|string']);

        $user = User::where('reg_no', $request->reg_no)
        ->where('school_id', $schoolId)
        ->first();

        if (!$user) {
            return response()->json(['message' => 'Staff not found'], 404);
        }

        return response()->json($user);
    }

public function generateForStaff(Request $request)
{
    $request->validate(['reg_no' => 'required|string']);

    $user = User::where('reg_no', $request->reg_no)->first();

    if (!$user) {
        return response()->json(['message' => 'Staff not found'], 404);
    }

    // Update or create biometric record
    $biometric = BiometricId::updateOrCreate(
        ['user_id' => $user->id],
        [
            'biometric_code' => Str::random(10),
            'school_id'      => Auth::user()->school_id,
            'status'         => 'active',
            'expires_at'     => Carbon::now()->addYear(1),
        ]
    );

    return response()->json([
        'id' => $biometric->id,
        'biometric_code' => $biometric->biometric_code,
        'expires_at'     => $biometric->expires_at,
        'staff' => [
            'firstname' => $user->firstname,
            'surname'   => $user->surname,
            'reg_no'    => $user->reg_no,
            'email'     => $user->email,
        ],
    ]);
}

 public function validateCode(Request $request)
    {
        $request->validate([
            'biometric_code' => 'required|string',
        ]);

        $biometric = BiometricId::with('user')
            ->where('biometric_code', $request->biometric_code)
            ->first();

        if (!$biometric) {
            return response()->json(['success' => false, 'message' => 'QR Code not found'], 404);
        }

        if (Carbon::parse($biometric->expires_at)->isPast()) {
            return response()->json(['success' => false, 'message' => 'QR Code expired'], 400);
        }

     return response()->json([
        'success' => true,
        'user' => [
            'id' => $biometric->user->id,
            'firstname' => $biometric->user->firstname,
            'surname' => $biometric->user->surname,
            'email' => $biometric->user->email,
        ],
        ]);

    }



// List all for school
public function show()
{
    $school_id = Auth::user()->school_id;
    $biometrics = BiometricId::with('teacher')
        ->where('school_id', $school_id)
        ->latest()
        ->get();

    return response()->json($biometrics);
}

// Delete biometric (and its QR code)
public function destroy($id)
{
    $biometric = BiometricId::find($id);

    if (!$biometric) {
        return response()->json(['message' => 'Biometric not found'], 404);
    }

    $biometric->delete();

    return response()->json(['message' => 'Biometric deleted successfully']);
}


}
