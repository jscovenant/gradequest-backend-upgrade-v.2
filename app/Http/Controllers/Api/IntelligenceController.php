<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AcademicInsightService;
use App\Services\RevenueIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntelligenceController extends Controller
{
    public function revenue(Request $request, RevenueIntelligenceService $service): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'integer'],
            'term_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
        ]);

        return response()->json($service->metrics(
            (int) $request->user()->school_id,
            isset($data['session_id']) ? (int) $data['session_id'] : null,
            isset($data['term_id']) ? (int) $data['term_id'] : null,
            isset($data['class_id']) ? (int) $data['class_id'] : null,
        ));
    }

    public function student(Request $request, User $student, AcademicInsightService $service): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        abort_unless((int) $student->school_id === $schoolId && strtolower((string) $student->role) === 'student', 404);

        return response()->json($service->studentTrend($schoolId, (int) $student->id));
    }
}
