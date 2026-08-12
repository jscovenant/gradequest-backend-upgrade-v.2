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

        if (! in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'teacher', 'principal'], true)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $schoolId = (int) ($auth->school_id ?? 0);
        $summary = $service->getCreditSummary($schoolId);

        return response()->json(['data' => $summary]);
    }
}
