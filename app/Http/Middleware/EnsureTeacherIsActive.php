<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeacherIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && in_array(strtolower((string) $user->role), ['teacher', 'class_teacher', 'subject_teacher', 'staff'], true)) {
            $teacherStatus = strtolower((string) ($user->teacher_status ?? 'active'));

            if ($teacherStatus !== 'active') {
                return response()->json([
                    'message' => 'Access denied. Your staff account is not active. Please contact the school administrator.',
                    'reason' => 'teacher_account_not_active',
                    'teacher_status' => $teacherStatus,
                ], 403);
            }
        }

        return $next($request);
    }
}
