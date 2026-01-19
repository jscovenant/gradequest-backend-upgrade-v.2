<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class ProfileController extends Controller
{
    // View user profile
    public function show(Request $request)
    {
        return response()->json($request->user());
    }

    // Update user profile


    public function update(Request $request)
{
    $user = User::find(Auth::id());

    $validated = $request->validate([
        'firstname' => 'required|string|max:50',
        'surname'   => 'required|string|max:50',
        'email'     => 'required|email|unique:users,email,' . $user->id,
        'phone'     => 'nullable|string|max:20',
        'address'   => 'nullable|string|max:255',
        'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',

    ]);

    if ($request->hasFile('photo')) {
        $filename = time() . '_' . $request->photo->getClientOriginalName();

        if ($user->photo && file_exists(public_path(parse_url($user->photo, PHP_URL_PATH)))) {
            unlink(public_path(parse_url($user->photo, PHP_URL_PATH)));
        }
        
    
        // Save directly to public/uploads/user
        $request->photo->move(public_path('uploads/user'), $filename);
    
        // Save the public URL in the DB
        $validated['photo'] = asset('uploads/user/' . $filename);
    }
    

    $user->update($validated);

    return response()->json($user);
}


public function updatePassword(Request $request)
{
    $user = User::find(Auth::id());

    $validator = Validator::make($request->all(), [
        'old_password' => 'required|string',
        'new_password' => 'required|string|min:8|confirmed',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    if (!Hash::check($request->old_password, $user->password)) {
        return response()->json(['message' => 'Old password is incorrect.'], 422);
    }

    $user->password = Hash::make($request->new_password);
    $user->save();

    return response()->json(['message' => 'Password updated successfully.'], 200);
}

}
