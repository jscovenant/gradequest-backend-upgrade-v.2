<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionWhatsappCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWhatsappCreditController extends Controller
{
    public function summary(Request $request, SubscriptionWhatsappCreditService $service): JsonResponse
    {
        $auth = $request->user();

        if (($auth->role ?? null) !== 'Admin') {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $schoolId = (int) ($auth->school_id ?? 0);

        $summary = $service->getCreditSummary($schoolId);

        return response()->json([
            'data' => $summary,
        ]);
    }
}