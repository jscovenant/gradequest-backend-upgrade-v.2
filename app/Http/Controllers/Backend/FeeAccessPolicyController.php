<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\SchoolFeeAccessPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeAccessPolicyController extends Controller
{
    public function __construct(private SchoolFeeAccessPolicyService $service)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'policy' => $this->service->policyForSchool((int) $request->user()->school_id),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'result_access_enabled' => ['required', 'boolean'],
            'result_min_payment_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'result_scope' => ['required', 'in:selected_period,all_outstanding'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'message' => 'Fee access policy saved successfully.',
            'policy' => $this->service->updatePolicy((int) $request->user()->school_id, $validated),
        ]);
    }
}
