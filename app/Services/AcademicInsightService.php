<?php

namespace App\Services;

use App\Models\ResultBatch;
use App\Models\StudentResultV2;

class AcademicInsightService
{
    public function studentTrend(int $schoolId, int $studentId, int $limit = 6): array
    {
        $results = StudentResultV2::query()
            ->where('user_id', $studentId)
            ->whereHas('batch', fn ($q) => $q->where('school_id', $schoolId)->where('status', 'published'))
            ->with(['batch:id,school_id,class_id,term,session,published_at', 'subjectResults.subject:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $periods = $results->map(function (StudentResultV2 $result): array {
            $scores = $result->subjectResults->map(fn ($subject) => is_numeric($subject->total) ? (float) $subject->total : null)->filter(fn ($score) => $score !== null);
            $average = $scores->isNotEmpty() ? round((float) $scores->avg(), 2) : (is_numeric($result->total_average) ? round((float) $result->total_average, 2) : null);

            return [
                'batch_id' => (int) $result->batch_id,
                'session' => $result->batch->session,
                'term' => $result->batch->term,
                'average' => $average,
                'subjects' => $result->subjectResults->map(fn ($subject) => [
                    'subject_id' => (int) $subject->subject_id,
                    'subject' => $subject->subject?->name,
                    'score' => is_numeric($subject->total) ? round((float) $subject->total, 2) : null,
                ])->values()->all(),
            ];
        });

        $latest = $periods->last();
        $previous = $periods->count() > 1 ? $periods->get($periods->count() - 2) : null;
        $change = $latest && $previous && $latest['average'] !== null && $previous['average'] !== null
            ? round($latest['average'] - $previous['average'], 2)
            : null;

        $latestSubjects = collect($latest['subjects'] ?? [])->whereNotNull('score')->sortBy('score')->values();

        return [
            'periods' => $periods->all(),
            'latest_average' => $latest['average'] ?? null,
            'previous_average' => $previous['average'] ?? null,
            'average_change' => $change,
            'trend' => $change === null ? 'insufficient_data' : ($change > 0 ? 'improved' : ($change < 0 ? 'declined' : 'stable')),
            'weakest_subjects' => $latestSubjects->take(3)->values()->all(),
            'strongest_subjects' => $latestSubjects->reverse()->take(3)->values()->all(),
            'at_risk' => ($latest['average'] ?? 100) < 50 || ($change !== null && $change <= -10),
        ];
    }
}
