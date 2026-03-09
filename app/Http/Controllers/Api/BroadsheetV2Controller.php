<?php
// app/Http/Controllers/V2/BroadsheetV2Controller.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BroadsheetV2Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class BroadsheetV2Controller extends Controller
{
   

    public function index(Request $request, int $batch, BroadsheetV2Service $svc): JsonResponse
    {
        $validated = $request->validate([
            'include_previous' => 'nullable|boolean',
            'rank_by' => 'nullable|in:average,total',

            // ✅ new filters
            'subject_id' => 'nullable|integer',
            'min_score'  => 'nullable|numeric|min:0',
            'max_score'  => 'nullable|numeric|min:0',
            'order'      => 'nullable|in:asc,desc',
        ]);

        $includePrevious = (bool)($validated['include_previous'] ?? false);
        $rankBy = $validated['rank_by'] ?? 'average';

        // ✅ build full broadsheet first (your existing logic)
        $data = $svc->build($batch, $includePrevious, $rankBy);

        // ✅ if no subject filter, return normal broadsheet
        $subjectId = $validated['subject_id'] ?? null;
        if (!$subjectId) {
            return response()->json($data);
        }

        $min = $validated['min_score'] ?? null;
        $max = $validated['max_score'] ?? null;
        $order = $validated['order'] ?? 'desc';

        $sid = (string)$subjectId;

        // ✅ filter rows by subject score
        $filteredRows = collect($data['rows'] ?? [])
            ->filter(function ($row) use ($sid, $includePrevious, $min, $max) {
                $cell = $row['subjects'][$sid] ?? null;
                if (!$cell) return false;

                $score = $includePrevious ? ($cell['effective_total'] ?? null) : ($cell['total'] ?? null);
                if ($score === null) return false;

                if ($min !== null && $score < $min) return false;
                if ($max !== null && $score > $max) return false;

                return true;
            })
            ->sortBy(function ($row) use ($sid, $includePrevious) {
                $cell = $row['subjects'][$sid] ?? null;
                return $includePrevious ? ($cell['effective_total'] ?? 0) : ($cell['total'] ?? 0);
            }, SORT_REGULAR, $order === 'desc')
            ->values()
            ->all();

        // ✅ return same structure, but rows filtered + sorted
        $data['rows'] = $filteredRows;

        // ✅ add filter meta so frontend can show “Filtered by …”
        $data['meta']['filtered_by_subject_id'] = $subjectId;
        $data['meta']['min_score'] = $min;
        $data['meta']['max_score'] = $max;
        $data['meta']['order'] = $order;

        return response()->json($data);
    }

    public function compute(Request $request, int $batch, BroadsheetV2Service $svc): JsonResponse
    {
        $validated = $request->validate([
            'include_previous' => 'nullable|boolean',
            'rank_by' => 'nullable|in:average,total',
        ]);

        $includePrevious = (bool)($validated['include_previous'] ?? false);
        $rankBy = $validated['rank_by'] ?? 'average';

        $data = $svc->computeAndPersist($batch, $includePrevious, $rankBy);

        return response()->json($data);
    }

    public function student(Request $request, int $batch, int $student, BroadsheetV2Service $svc): JsonResponse
    {
        $includePrevious = (bool)$request->query('include_previous', false);

        $row = $svc->buildStudent($batch, $student, $includePrevious);

        return response()->json($row);
    }

    public function export(Request $request, int $batch, BroadsheetV2Service $svc)
    {
        $format = (string)$request->query('format', 'csv'); // csv|xlsx (csv implemented)
        $includePrevious = (bool)$request->query('include_previous', false);
        $rankBy = (string)$request->query('rank_by', 'average');

        $data = $svc->build($batch, $includePrevious, $rankBy);

        return $svc->export($data, $format);
    }
}