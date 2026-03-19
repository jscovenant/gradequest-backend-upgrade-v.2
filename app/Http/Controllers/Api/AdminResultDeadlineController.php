<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResultBatch;
use App\Models\ResultSubmissionMonitor;
use App\Models\StudentClass;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AdminResultDeadlineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (($user->role ?? null) !== 'Admin') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $schoolId = (int) ($user->school_id ?? 0);

        if (!$schoolId) {
            return response()->json([
                'message' => 'School not found for this user.'
            ], 422);
        }

        $query = ResultBatch::query()
            ->where('school_id', $schoolId);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('term')) {
            $query->where('term', $request->string('term')->toString());
        }

        if ($request->filled('session')) {
            $query->where('session', $request->string('session')->toString());
        }

        $batches = $query
            ->orderByDesc('id')
            ->get([
                'id',
                'school_id',
                'class_id',
                'term',
                'session',
                'status',
                'submission_deadline',
                'created_at',
                'updated_at',
            ]);

        $classIds = $batches->pluck('class_id')->filter()->unique()->values();

        $classMap = StudentClass::query()
            ->whereIn('id', $classIds)
            ->pluck('name', 'id');

        $items = $batches->map(function ($batch) use ($classMap) {
            return [
                'id' => $batch->id,
                'school_id' => $batch->school_id,
                'class_id' => $batch->class_id,
                'class_name' => $classMap[$batch->class_id] ?? "Class {$batch->class_id}",
                'term' => $batch->term,
                'session' => $batch->session,
                'status' => $batch->status,
                'submission_deadline' => optional($batch->submission_deadline)?->toDateString(),
                'created_at' => optional($batch->created_at)?->toDateTimeString(),
                'updated_at' => optional($batch->updated_at)?->toDateTimeString(),
            ];
        });

        return response()->json([
            'data' => $items,
        ]);
    }

    public function setDeadline(Request $request, int $batch): JsonResponse
    {
        $user = $request->user();

        if (($user->role ?? null) !== 'Admin') {
            return response()->json([
                'message' => 'Unauthorized.'
            ], 403);
        }

        $data = $request->validate([
            'submission_deadline' => ['required', 'date', 'after:today']
        ]);

        $batch = ResultBatch::query()
            ->where('id', $batch)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        $deadline = Carbon::parse($data['submission_deadline'])->endOfDay();

        $batch->update([
            'submission_deadline' => $deadline,
        ]);

        $monitor = ResultSubmissionMonitor::updateOrCreate(
            [
                'school_id' => $batch->school_id,
                'batch_id' => $batch->id,
                'class_id' => $batch->class_id,
            ],
            [
                'teacher_id' => $batch->created_by,
                'term' => $batch->term,
                'session' => $batch->session,
                'submission_deadline' => $deadline,
                'status' => 'pending',
            ]
        );

        return response()->json([
            'message' => 'Submission deadline updated successfully.',
            'data' => [
                'batch_id' => $batch->id,
                'class_id' => $batch->class_id,
                'submission_deadline' => $deadline->toDateString(),
                'monitor_id' => $monitor->id,
            ]
        ]);
    }
}