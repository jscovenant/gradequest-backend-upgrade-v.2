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
            'cbt_access_enabled' => ['sometimes', 'boolean'],
            'cbt_min_payment_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'cbt_scope' => ['sometimes', 'in:selected_period,all_outstanding'],
            'installment_enabled' => ['sometimes', 'boolean'],
            'installment_type' => ['sometimes', 'string', 'in:two_installments_70_30,two_installments_50_50,three_installments_40_30_30,full_only,custom'],
            'min_initial_installment_percent' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'message' => ['nullable', 'string', 'max:255'],
            'cbt_message' => ['nullable', 'string', 'max:255'],
            'installment_message' => ['nullable', 'string', 'max:255'],
            'bank_charge_bearer' => ['sometimes', 'string', 'in:parent,school'],
            'bank_charge_amount' => ['sometimes', 'numeric', 'min:0', 'max:10000'],
            'platform_fee_bearer' => ['sometimes', 'string', 'in:parent,school'],
            'active_edition_tier' => ['sometimes', 'string', 'in:basic_result,standard_cbt,annual_full_session'],
            'active_payment_gateway' => ['sometimes', 'string', 'in:wema_alat,monnify,paystack'],
            'full_payment_discount_enabled' => ['sometimes', 'boolean'],
            'full_payment_discount_type' => ['sometimes', 'string', 'in:percentage,fixed'],
            'full_payment_discount_value' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'full_payment_discount_message' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'message' => 'Fee access and installment policy saved successfully.',
            'policy' => $this->service->updatePolicy((int) $request->user()->school_id, $validated),
        ]);
    }
}
