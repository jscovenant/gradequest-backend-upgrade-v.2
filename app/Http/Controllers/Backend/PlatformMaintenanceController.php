<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Mail\PlatformMaintenanceNoticeMail;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PlatformMaintenanceController extends Controller
{
    private static function getStoragePath(): string
    {
        return storage_path('app/maintenance_mode.json');
    }

    public static function getMaintenanceState(): array
    {
        $path = self::getStoragePath();
        $defaultMsg = 'We are working harder to make things better, please hold on...';

        if (File::exists($path)) {
            try {
                $data = json_decode(File::get($path), true);
                if (is_array($data)) {
                    return array_merge([
                        'is_active' => false,
                        'is_scheduled' => false,
                        'start_time' => null,
                        'end_time' => null,
                        'message' => $defaultMsg,
                        'activated_at' => null,
                        'activated_by' => null,
                        'allowed_roles' => ['Super-Admin', 'Platform-Staff'],
                        'last_emailed_at' => null,
                    ], $data);
                }
            } catch (\Throwable $e) {
                // Fallback to defaults
            }
        }

        return [
            'is_active' => false,
            'is_scheduled' => false,
            'start_time' => null,
            'end_time' => null,
            'message' => $defaultMsg,
            'activated_at' => null,
            'activated_by' => null,
            'allowed_roles' => ['Super-Admin', 'Platform-Staff'],
            'last_emailed_at' => null,
        ];
    }

    /**
     * Get the current maintenance state for Super Admins
     */
    public function getStatus()
    {
        $state = self::getMaintenanceState();

        // Calculate if currently in effect right now
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

        $state['is_in_effect'] = $isInEffect;

        return response()->json([
            'success' => true,
            'status' => $state,
        ]);
    }

    /**
     * Toggle or schedule maintenance mode
     */
    public function toggle(Request $request)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
            'is_scheduled' => 'nullable|boolean',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'message' => 'nullable|string|max:500',
            'send_email_notification' => 'nullable|boolean',
            'allowed_roles' => 'nullable|array',
        ]);

        $user = Auth::user();
        $currentState = self::getMaintenanceState();
        $defaultMsg = 'We are working harder to make things better, please hold on...';

        $isActive = (bool) $validated['is_active'];
        $isScheduled = !empty($validated['is_scheduled']);
        $startTime = !empty($validated['start_time']) ? $validated['start_time'] : null;
        $endTime = !empty($validated['end_time']) ? $validated['end_time'] : null;
        $msg = !empty($validated['message']) ? trim($validated['message']) : $defaultMsg;

        $newState = [
            'is_active' => $isActive,
            'is_scheduled' => $isScheduled,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'message' => $msg,
            'activated_at' => $isActive ? now()->toIso8601String() : null,
            'activated_by' => ($isActive || $isScheduled) ? ($user ? ($user->name ?? $user->email) : 'Super Admin') : null,
            'allowed_roles' => $validated['allowed_roles'] ?? ['Super-Admin', 'Platform-Staff'],
            'last_emailed_at' => $currentState['last_emailed_at'] ?? null,
        ];

        // Send Email Notice to All School Admins if requested
        $emailCount = 0;
        if (!empty($validated['send_email_notification']) && ($isActive || $isScheduled)) {
            $admins = User::whereIn('role', ['Admin', 'admin'])
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get();

            foreach ($admins as $admin) {
                try {
                    Mail::to($admin->email)->queue(
                        new PlatformMaintenanceNoticeMail(
                            $admin,
                            $msg,
                            $startTime,
                            $endTime,
                            $isActive
                        )
                    );
                    $emailCount++;
                } catch (\Throwable $e) {
                    Log::warning("Failed to queue maintenance email to {$admin->email}: " . $e->getMessage());
                }
            }
            $newState['last_emailed_at'] = now()->toIso8601String();
        }

        $path = self::getStoragePath();
        $directory = dirname($path);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, json_encode($newState, JSON_PRETTY_PRINT));

        // Log audit trail
        try {
            $action = $isActive ? 'MAINTENANCE_ENABLED' : ($isScheduled ? 'MAINTENANCE_SCHEDULED' : 'MAINTENANCE_DISABLED');
            $desc = $isActive
                ? "Platform maintenance mode ENABLED with message: \"{$msg}\""
                : ($isScheduled
                    ? "Platform maintenance SCHEDULED from {$startTime} to {$endTime}"
                    : "Platform maintenance mode DISABLED. Normal operations resumed.");

            if ($emailCount > 0) {
                $desc .= " (Dispatched notice email to {$emailCount} school admins).";
            }

            ActivityLog::create([
                'user_id' => $user ? $user->id : null,
                'action' => $action,
                'description' => $desc,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log maintenance activity: ' . $e->getMessage());
        }

        $msgResult = $isActive
            ? 'Platform maintenance mode is now ACTIVE (Read-Only mode enabled for schools).'
            : ($isScheduled
                ? "Platform maintenance scheduled successfully."
                : 'Platform maintenance mode is now DEACTIVATED.');

        if ($emailCount > 0) {
            $msgResult .= " Advance notice emailed to {$emailCount} school administrators.";
        }

        return response()->json([
            'success' => true,
            'message' => $msgResult,
            'status' => $newState,
            'emails_queued' => $emailCount,
        ]);
    }

    /**
     * Public lightweight status check
     */
    public function publicStatus()
    {
        $state = self::getMaintenanceState();

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

        return response()->json([
            'maintenance_mode' => $isInEffect,
            'is_scheduled' => (bool) ($state['is_scheduled'] ?? false),
            'start_time' => $state['start_time'] ?? null,
            'end_time' => $state['end_time'] ?? null,
            'message' => $state['message'] ?? 'We are working harder to make things better, please hold on...',
            'activated_at' => $state['activated_at'] ?? null,
        ]);
    }
}
