<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Mail\SchoolOperatorWelcomeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SchoolOperatorController extends Controller
{
    /**
     * List all operators for the school
     */
    public function index(Request $request)
    {
        $schoolId = $request->user()->school_id;

        $operators = User::where('school_id', $schoolId)
            ->where(function ($q) {
                $q->whereRaw('LOWER(role) = ?', ['operator'])
                  ->orWhere('role', 'Operator');
            })
            ->orderBy('created_at', 'desc')
            ->get([
                'id',
                'firstname',
                'surname',
                'email',
                'phone',
                'address',
                'role',
                'status',
                'operator_title',
                'last_login_at',
                'created_at',
            ]);

        return response()->json([
            'status' => 'success',
            'operators' => $operators,
        ]);
    }

    /**
     * Create / Register a new School Operator
     */
    public function store(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'firstname' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:25',
            'operator_title' => 'nullable|string|max:100',
        ]);

        $randomPassword = Str::random(8);
        $title = $validated['operator_title'] ?: 'Desk Officer / School Operator';

        $operator = User::create([
            'firstname' => $validated['firstname'],
            'surname' => $validated['surname'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => 'Operator',
            'operator_title' => $title,
            'school_id' => $admin->school_id,
            'password' => Hash::make($randomPassword),
            'default_password' => $randomPassword,
            'status' => 1,
            'force_password_change' => true,
        ]);

        // Send welcome email with login credentials
        try {
            Mail::to($operator->email)->send(new SchoolOperatorWelcomeMail(
                $operator,
                $randomPassword,
                $admin->school?->school_name ?? 'Your School',
                $title
            ));
        } catch (\Throwable $e) {
            Log::warning("Could not email operator credentials to {$operator->email}: " . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => "School Operator {$operator->firstname} {$operator->surname} registered successfully.",
            'operator' => $operator,
            'temporary_password' => $randomPassword,
        ], 201);
    }

    /**
     * Update an operator's profile details
     */
    public function update(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;

        $operator = User::where('school_id', $schoolId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'firstname' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'phone' => 'nullable|string|max:25',
            'operator_title' => 'nullable|string|max:100',
        ]);

        $operator->update([
            'firstname' => $validated['firstname'],
            'surname' => $validated['surname'],
            'phone' => $validated['phone'] ?? $operator->phone,
            'operator_title' => $validated['operator_title'] ?? $operator->operator_title,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Operator details updated successfully.',
            'operator' => $operator,
        ]);
    }

    /**
     * Activate or Deactivate an operator
     */
    public function toggleStatus(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;

        $operator = User::where('school_id', $schoolId)
            ->where('id', $id)
            ->firstOrFail();

        $newStatus = (int) $operator->status === 1 ? 0 : 1;
        $operator->status = $newStatus;
        $operator->save();

        if ($newStatus === 0) {
            // Revoke active sessions immediately
            $operator->tokens()->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => $newStatus === 1 ? 'Operator account activated.' : 'Operator account deactivated and active sessions terminated.',
            'new_status' => $newStatus,
            'operator' => $operator,
        ]);
    }

    /**
     * Reset operator password
     */
    public function resetPassword(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $admin = $request->user();

        $operator = User::where('school_id', $schoolId)
            ->where('id', $id)
            ->firstOrFail();

        $newPassword = $request->input('new_password') ?: Str::random(8);

        $operator->password = Hash::make($newPassword);
        $operator->default_password = $newPassword;
        $operator->force_password_change = true;
        $operator->save();

        // Invalidate previous sessions
        $operator->tokens()->delete();

        try {
            Mail::to($operator->email)->send(new SchoolOperatorWelcomeMail(
                $operator,
                $newPassword,
                $admin->school?->school_name ?? 'Your School',
                $operator->operator_title ?: 'School Operator'
            ));
        } catch (\Throwable $e) {
            Log::warning("Could not email reset password to {$operator->email}: " . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => "Password reset successfully. A notification has been emailed to {$operator->email}.",
            'temporary_password' => $newPassword,
        ]);
    }

    /**
     * Delete an operator
     */
    public function destroy(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;

        $operator = User::where('school_id', $schoolId)
            ->where('id', $id)
            ->firstOrFail();

        $operator->tokens()->delete();
        $operator->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Operator account deleted successfully.',
        ]);
    }
}
