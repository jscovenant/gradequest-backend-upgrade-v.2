<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\SchoolBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentClearanceController extends Controller
{
    public function __construct(private readonly SchoolBillingService $billing)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $sessionId = $request->query('session_id') ? (int) $request->query('session_id') : null;
        $termId = $request->query('term_id') ? (int) $request->query('term_id') : null;

        $summary = $this->billing->clearanceSummary($schoolId, $sessionId, $termId);

        return response()->json($summary);
    }

    public function clearStudent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|integer',
            'session_id' => 'required|integer',
            'term_id'    => 'required|integer',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $result = $this->billing->clearStudentFromWallet(
                $schoolId,
                (int) $validated['student_id'],
                (int) $validated['session_id'],
                (int) $validated['term_id'],
                $actorId
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function clearClass(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id'   => 'required|integer',
            'session_id' => 'required|integer',
            'term_id'    => 'required|integer',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $result = $this->billing->clearClassFromWallet(
                $schoolId,
                (int) $validated['class_id'],
                (int) $validated['session_id'],
                (int) $validated['term_id'],
                $actorId
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function clearSchoolTerm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|integer',
            'term_id'    => 'required|integer',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $result = $this->billing->clearSchoolTermFromWallet(
                $schoolId,
                (int) $validated['session_id'],
                (int) $validated['term_id'],
                $actorId
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function clearSchoolSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|integer',
        ]);

        $schoolId = (int) $request->user()->school_id;
        $actorId = (int) $request->user()->id;

        try {
            $result = $this->billing->clearSchoolSessionFromWallet(
                $schoolId,
                (int) $validated['session_id'],
                $actorId
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function studentStatus(Request $request, int $studentId): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $clearance = $this->billing->studentAcademicClearanceStatus($schoolId, $studentId);

        return response()->json($clearance);
    }
}
