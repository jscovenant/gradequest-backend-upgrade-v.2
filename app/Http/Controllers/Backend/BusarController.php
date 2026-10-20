<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

use App\Models\BursarSection;
use App\Models\Section;

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
            'section_ids' => 'nullable|array',
            'section_ids.*' => 'exists:sections,id',
        ]);

        $randomPassword = Str::random(8);

        $sectionIds = array_values(array_filter(array_unique($request->section_ids ?? [])));

        $bursar = User::create([
            'firstname' => $request->firstname,
            'surname' => $request->surname,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'role' => 'Bursar',
            'school_id' => $auth->school_id,
            'section_id' => count($sectionIds) === 1 ? $sectionIds[0] : null,
            'password' => Hash::make($randomPassword),
            'default_password' => $randomPassword,
            'status' => 1,
        ]);

        $bursar->assignRole('Bursar');

        if (!empty($sectionIds)) {
            foreach ($sectionIds as $secId) {
                BursarSection::firstOrCreate([
                    'school_id' => $auth->school_id,
                    'user_id' => $bursar->id,
                    'section_id' => $secId,
                ]);
            }
        }

        $bursar->load('assignedSections:id,name');

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
        $bursars = User::withRole('bursar')
                       ->with('assignedSections:id,name')
                       ->where('school_id', $auth->school_id)
                       ->get()
                       ->map(function ($b) {
                           $b->assigned_section_ids = $b->assignedSections->pluck('id')->toArray();
                           return $b;
                       });

        return response()->json([
            'bursars' => $bursars,
        ]);
    }

  public function show($id)
{
    $auth = Auth::user();

    $bursar = User::withRole('bursar')
        ->with('assignedSections:id,name')
        ->where('school_id', $auth->school_id)
        ->findOrFail($id);

    $decryptedPassword = $this->readableDefaultPassword($bursar) ?: 'N/A';

    return response()->json([
        'bursar' => [
            'id' => $bursar->id,
            'firstname' => $bursar->firstname,
            'surname' => $bursar->surname,
            'email' => $bursar->email,
            'phone' => $bursar->phone,
            'address' => $bursar->address,
            'default_password' => $decryptedPassword,
            'section_id' => $bursar->section_id,
            'assigned_sections' => $bursar->assignedSections,
            'assigned_section_ids' => $bursar->assignedSections->pluck('id')->toArray(),
        ],
    ]);
}

public function edit($id)
{
    $auth = Auth::user();

    $bursar = User::where('id', $id)
        ->where('school_id', $auth->school_id)
        ->withRole('bursar')
        ->with('assignedSections:id,name')
        ->firstOrFail();

    $decryptedPassword = $this->readableDefaultPassword($bursar);

    return response()->json([
        'bursar' => [
            'id' => $bursar->id,
            'firstname' => $bursar->firstname,
            'surname' => $bursar->surname,
            'email' => $bursar->email,
            'phone' => $bursar->phone,
            'address' => $bursar->address,
            'default_password' => $decryptedPassword,
            'section_id' => $bursar->section_id,
            'assigned_sections' => $bursar->assignedSections,
            'assigned_section_ids' => $bursar->assignedSections->pluck('id')->toArray(),
        ],
    ]);
}

    // ✅ Update bursar details
   public function update(Request $request, $id)
{
    $auth = Auth::user();

    $bursar = User::withRole('bursar')
        ->forSchool($auth->school_id)
        ->findOrFail($id);

    $request->validate([
        'firstname' => 'sometimes|required|string|max:100',
        'surname' => 'sometimes|required|string|max:100',
        'email' => 'sometimes|required|email|unique:users,email,' . $bursar->id,
        'phone' => 'sometimes|required|string|max:20|unique:users,phone,' . $bursar->id,
        'address' => 'nullable|string|max:255',
        'status' => 'nullable|in:0,1',
        'password' => 'nullable|string|min:6',
        'section_ids' => 'nullable|array',
        'section_ids.*' => 'exists:sections,id',
    ]);

    $data = $request->only('firstname', 'surname', 'email', 'phone', 'address', 'status');

    if ($request->has('section_ids')) {
        $sectionIds = array_values(array_filter(array_unique($request->section_ids ?? [])));
        BursarSection::where('user_id', $bursar->id)->delete();
        foreach ($sectionIds as $secId) {
            BursarSection::firstOrCreate([
                'school_id' => $auth->school_id,
                'user_id' => $bursar->id,
                'section_id' => $secId,
            ]);
        }
        $data['section_id'] = count($sectionIds) === 1 ? $sectionIds[0] : null;
    }

    if ($request->filled('password')) {
        $data['password'] = Hash::make($request->password);
        $data['default_password'] = $request->password;
    }

    $bursar->update($data);
    $bursar->load('assignedSections:id,name');

    return response()->json([
        'message' => 'Bursar updated successfully',
        'bursar' => $bursar,
    ]);
}



    // ✅ Delete bursar
    public function destroy($id)
    {
        $auth = Auth::user();

        $bursar = User::withRole('bursar')
            ->forSchool($auth->school_id)
            ->findOrFail($id);
        $bursar->delete();

        return response()->json([
            'message' => 'Bursar deleted successfully',
        ]);
    }


    public function decryptPassword(Request $request)
{
    $request->validate([
        'user_id' => 'required|integer|exists:users,id',
    ]);

    $auth = Auth::user();

    $student = User::withRole('bursar')
        ->forSchool($auth->school_id)
        ->findOrFail($request->user_id);

    $decryptedPassword = $this->readableDefaultPassword($student);

    if (!$decryptedPassword) {
        return response()->json([
            'success' => false,
            'message' => 'No default password found for this user. Reset the password and try again.',
        ], 404);
    }

    return response()->json([
        'success' => true,
        'message' => 'Password fetched successfully',
        'decrypted_password' => $decryptedPassword,
    ], 200);
}
private function readableDefaultPassword(User $user): ?string
{
    try {
        return $user->default_password ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}
}


