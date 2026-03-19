<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WhatsAppIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappVerificationController extends Controller
{
    public function startAdmin(Request $request, WhatsAppIdentityService $service): JsonResponse
    {
        $auth = $request->user();

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $result = $service->startAdminVerification($auth, $data['phone']);

        return response()->json($result);
    }

    public function startParent(Request $request, WhatsAppIdentityService $service): JsonResponse
    {
        $auth = $request->user();

        $result = $service->startParentVerification($auth);

        return response()->json($result);
    }

    public function verify(Request $request, WhatsAppIdentityService $service): JsonResponse
    {
        $auth = $request->user();

        $data = $request->validate([
            'verification_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $result = $service->verifyCode($auth, (int) $data['verification_id'], $data['code']);

        return response()->json($result);
    }
}