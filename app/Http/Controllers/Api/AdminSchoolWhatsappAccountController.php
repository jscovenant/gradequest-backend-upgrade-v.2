<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SchoolWhatsappAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSchoolWhatsappAccountController extends Controller
{
    public function store(Request $request, SchoolWhatsappAccountService $service): JsonResponse
    {
        $auth = $request->user();

        $data = $request->validate([
            
            'phone_number_id' => ['required', 'string', 'max:255'],
            'display_phone_number' => ['nullable', 'string', 'max:255'],
            'verified_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,active,disconnected,suspended'],
            'meta_payload' => ['nullable', 'array'],
        ]);

        $account = $service->connect($auth, $data);

        return response()->json([
            'message' => 'School WhatsApp account connected successfully.',
            'data' => $account,
        ]);
    }


    public function show(Request $request): JsonResponse
{
    $auth = $request->user();

    if (($auth->role ?? null) !== 'Admin') {
        return response()->json([
            'message' => 'Unauthorized.',
        ], 403);
    }

    $schoolId = (int) ($auth->school_id ?? 0);

    $account = \App\Models\SchoolWhatsappAccount::query()
        ->where('school_id', $schoolId)
        ->first();

    return response()->json([
        'data' => $account,
    ]);
}
}