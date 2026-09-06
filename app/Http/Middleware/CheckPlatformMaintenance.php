<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Backend\PlatformMaintenanceController;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

class CheckPlatformMaintenance
{
    /**
     * Handle an incoming request.
     * Allows all Read-Only (GET) requests so school portals remain functional.
     * Blocks all mutating CRUD operations (POST, PUT, PATCH, DELETE) and payments during maintenance.
     */
    public function handle(Request $request, Closure $next)
    {
        $state = PlatformMaintenanceController::getMaintenanceState();

        // Check if maintenance is currently in effect
        $isInEffect = false;

        if (!empty($state['is_active'])) {
            $isInEffect = true;
        } elseif (!empty($state['is_scheduled']) && !empty($state['start_time'])) {
            $now = Carbon::now();
            $start = Carbon::parse($state['start_time']);
            $end = !empty($state['end_time']) ? Carbon::parse($state['end_time']) : null;

            if ($now->greaterThanOrEqualTo($start) && (!$end || $now->lessThanOrEqualTo($end))) {
                $isInEffect = true;
            }
        }

        if (!$isInEffect) {
            return $next($request);
        }

        // Exempt public status and Super Admin endpoints
        $path = $request->path();
        if (
            $path === 'api/platform-status' ||
            $path === 'api/public/system-status' ||
            $request->is('api/superadmin*') ||
            $request->is('api/paystack/webhook*') ||
            $request->is('api/webhook*')
        ) {
            return $next($request);
        }

        // Allow essential authentication so users can log in and view their dashboards
        if ($request->is('api/login*') || $request->is('api/logout*')) {
            return $next($request);
        }

        // Super-Admin and Platform-Staff have 100% full bypass
        $user = $request->user() ?: auth('sanctum')->user() ?: auth()->user();
        if ($user) {
            $userRole = strtolower($user->role ?? '');
            $allowedRoles = array_map('strtolower', $state['allowed_roles'] ?? ['super-admin', 'platform-staff']);

            if (in_array($userRole, $allowedRoles) || (method_exists($user, 'isSuperAdminUser') && $user->isSuperAdminUser()) || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
                return $next($request);
            }
        }

        // Allow all READ-ONLY (GET, HEAD, OPTIONS) requests across all portals
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        // Block ALL mutating CRUD operations (POST, PUT, PATCH, DELETE), payments, results, and CBT
        $responseMessage = !empty($state['message'])
            ? $state['message']
            : "We are working harder to make things better, please hold on...";

        return response()->json([
            'success' => false,
            'maintenance_mode' => true,
            'message' => $responseMessage,
            'notice' => 'System is in Read-Only maintenance mode. Data modifications and payments are temporarily paused.',
            'start_time' => $state['start_time'] ?? $state['activated_at'] ?? null,
            'end_time' => $state['end_time'] ?? null,
        ], 423); // 423 Locked / Read-Only Mode
    }
}
