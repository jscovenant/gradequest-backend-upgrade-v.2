<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SchoolWhatsappMessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWhatsappMessageController extends Controller
{


    public function index(Request $request): JsonResponse
{
    $auth = $request->user();

    if (($auth->role ?? null) !== 'Admin') {
        return response()->json([
            'message' => 'Unauthorized.',
        ], 403);
    }

    $schoolId = (int) ($auth->school_id ?? 0);

    $messages = \App\Models\WhatsappMessage::query()
        ->where('school_id', $schoolId)
        ->latest()
        ->paginate(20);

    return response()->json($messages);
}



    public function sendToParent(Request $request, SchoolWhatsappMessagingService $service): JsonResponse
    {

        $auth = $request->user();

        if (($auth->role ?? null) !== 'Admin') {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $schoolId = (int) ($auth->school_id ?? 0);

        $data = $request->validate([
            'parent_user_id' => ['required', 'integer'],
            'student_user_id' => ['nullable', 'integer'],
            'template_name' => ['required', 'string', 'max:255'],
            'lang' => ['nullable', 'string', 'max:20'],
            'body_params' => ['nullable', 'array'],
            'body_params.*' => ['nullable'],
            'credit_cost' => ['nullable', 'integer', 'min:1'],
        ]);

        $message = $service->sendToParent(
            schoolId: $schoolId,
            parentUserId: (int) $data['parent_user_id'],
            studentUserId: isset($data['student_user_id']) ? (int) $data['student_user_id'] : null,
            templateName: $data['template_name'],
            lang: $data['lang'] ?? 'en',
            bodyParams: $data['body_params'] ?? [],
            creditCost: (int) ($data['credit_cost'] ?? 1)
        );

        return response()->json([
            'message' => 'WhatsApp message sent successfully.',
            'data' => $message,
        ]);
    }


}