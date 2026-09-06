<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionAiCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAiCreditController extends Controller
{
    public function summary(Request $request, SubscriptionAiCreditService $service): JsonResponse
    {
        $auth = $request->user();

        if (! in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'teacher', 'principal', 'staff', 'bursar'], true)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $schoolId = (int) ($auth->school_id ?? 0);
        $summary = $service->getCreditSummary($schoolId, $auth);

        return response()->json(['data' => $summary]);
    }

    public function staffAllocations(Request $request, SubscriptionAiCreditService $service): JsonResponse
    {
        $auth = $request->user();

        if (! in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'principal'], true)) {
            return response()->json(['message' => 'Unauthorized. Only school administrators can manage AI credit allocations.'], 403);
        }

        $schoolId = (int) ($auth->school_id ?? 0);
        $data = $service->getStaffAllocations($schoolId);

        return response()->json(['data' => $data]);
    }

    public function allocateStaff(Request $request, SubscriptionAiCreditService $service): JsonResponse
    {
        $auth = $request->user();

        if (! in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'principal'], true)) {
            return response()->json(['message' => 'Unauthorized. Only school administrators can allocate AI credits.'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer',
            'allocated_credits' => 'required|integer|min:0|max:1000000',
            'is_unlimited' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        $schoolId = (int) ($auth->school_id ?? 0);
        $allocation = $service->allocateStaffCredits(
            $schoolId,
            (int) $validated['user_id'],
            (int) $validated['allocated_credits'],
            (int) $auth->id,
            (bool) ($validated['is_unlimited'] ?? false),
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => 'AI credit allocation updated successfully.',
            'data' => $allocation,
        ]);
    }

    public function bulkAllocateStaff(Request $request, SubscriptionAiCreditService $service): JsonResponse
    {
        $auth = $request->user();

        if (! in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'principal'], true)) {
            return response()->json(['message' => 'Unauthorized. Only school administrators can allocate AI credits.'], 403);
        }

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer',
            'allocated_credits' => 'required|integer|min:0|max:1000000',
            'is_unlimited' => 'nullable|boolean',
        ]);

        $schoolId = (int) ($auth->school_id ?? 0);
        $count = $service->bulkAllocateStaffCredits(
            $schoolId,
            $validated['user_ids'],
            (int) $validated['allocated_credits'],
            (int) $auth->id,
            (bool) ($validated['is_unlimited'] ?? false)
        );

        return response()->json([
            'message' => "Successfully allocated AI credits to {$count} staff member(s).",
            'count' => $count,
        ]);
    }

    public function revokeStaff(Request $request, int $userId, SubscriptionAiCreditService $service): JsonResponse
    {
        $auth = $request->user();

        if (! in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'principal'], true)) {
            return response()->json(['message' => 'Unauthorized. Only school administrators can revoke AI credit allocations.'], 403);
        }

        $schoolId = (int) ($auth->school_id ?? 0);
        $service->revokeStaffAllocation($schoolId, $userId);

        return response()->json([
            'message' => 'Staff AI credit allocation removed successfully.',
        ]);
    }
}
