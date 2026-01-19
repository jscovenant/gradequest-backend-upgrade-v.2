<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class BusarController extends Controller
{
    // ✅ Create / Register Bursar
    public function register(Request $request)
    {
        $auth = Auth::user();

        $request->validate([
            'firstname' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:20|unique:users,phone',
            'address' => 'nullable|string|max:255',
        ]);

        $randomPassword = Str::random(8);

        $bursar = User::create([
            'firstname' => $request->firstname,
            'surname' => $request->surname,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'role' => 'Bursar',
            'school_id' => $auth->school_id,
            'password' => Hash::make($randomPassword),
            'default_password' => encrypt($randomPassword),
            'status' => 1,
        ]);

        $bursar->assignRole('Bursar');

        return response()->json([
            'message' => 'Bursar registered successfully',
            'bursar' => $bursar,
            'default_password' => $randomPassword,
        ], 201);
    }

    // ✅ Get all bursars for a school
    public function index()
    {
        $auth = Auth::user();
        $bursars = User::where('role', 'Bursar')
                       ->where('school_id', $auth->school_id)
                       ->get();

        return response()->json([
            'bursars' => $bursars,
        ]);
    }

  public function show($id)
{
    $auth = Auth::user();

    $bursar = User::where('role', 'Bursar')
        ->where('school_id', $auth->school_id) // optional, if you want to restrict to your school
        ->findOrFail($id);

    // 🔹 Decrypt default password
    $decryptedPassword = null;
    try {
        $decryptedPassword = Crypt::decrypt($bursar->default_password);
    } catch (\Exception $e) {
        $decryptedPassword = 'N/A';
    }

    return response()->json([
        'bursar' => [
            'id' => $bursar->id,
            'firstname' => $bursar->firstname,
            'surname' => $bursar->surname,
            'email' => $bursar->email,
            'phone' => $bursar->phone,
            'address' => $bursar->address,
            'default_password' => $decryptedPassword,
        ],
    ]);
}

public function edit($id)
{
    $auth = Auth::user();

    // 🔹 Fetch bursar in the same school
    $bursar = User::where('id', $id)
        ->where('school_id', $auth->school_id)
        ->where('role', 'Bursar')
        ->firstOrFail();

    // 🔹 Decrypt default password
    $decryptedPassword = null;
    try {
        $decryptedPassword = Crypt::decrypt($bursar->default_password);
    } catch (\Exception $e) {
        $decryptedPassword = null; // or "N/A" if you prefer
    }

    return response()->json([
        'bursar' => [
            'id' => $bursar->id,
            'firstname' => $bursar->firstname,
            'surname' => $bursar->surname,
            'email' => $bursar->email,
            'phone' => $bursar->phone,
            'address' => $bursar->address,
            'default_password' => $decryptedPassword,
        ],
    ]);
}

    // ✅ Update bursar details
    public function update(Request $request, $id)
    {
        $bursar = User::where('role', 'Bursar')->findOrFail($id);

        $request->validate([
            'firstname' => 'sometimes|required|string|max:100',
            'surname' => 'sometimes|required|string|max:100',
            'email' => 'sometimes|required|email|unique:users,email,' . $bursar->id,
            'phone' => 'sometimes|required|string|max:20|unique:users,phone,' . $bursar->id,
            'address' => 'nullable|string|max:255',
            'status' => 'nullable|in:0,1',
        ]);

        $bursar->update($request->only('firstname', 'surname', 'email', 'phone', 'address', 'status'));

        return response()->json([
            'message' => 'Bursar updated successfully',
            'bursar' => $bursar,
        ]);
    }

    // ✅ Delete bursar
    public function destroy($id)
    {
        $bursar = User::where('role', 'Bursar')->findOrFail($id);
        $bursar->delete();

        return response()->json([
            'message' => 'Bursar deleted successfully',
        ]);
    }
}
