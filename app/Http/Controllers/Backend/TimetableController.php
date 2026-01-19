<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\StudentClass;
use App\Models\Timetable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TimetableController extends Controller
{
    /**
     * ✅ Generate new timetable for all classes
     * Automatically clears old timetables before regenerating
     */
  
public function generate(Request $request)
{
    $request->validate([
        'periods_per_day' => 'required|integer|min:1|max:10',
    ]);

    $schoolId = Auth::user()->school_id;
    $periodsPerDay = $request->periods_per_day;
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    DB::beginTransaction();

    try {
        $classes = StudentClass::with(['section.subjects'])
            ->where('school_id', $schoolId)
            ->get();

        if ($classes->isEmpty()) {
            return response()->json(['message' => 'No classes found.'], 404);
        }

        Timetable::where('school_id', $schoolId)->delete();

        foreach ($classes as $class) {
            if (!$class->section) {
                return response()->json([
                    'message' => "Class '{$class->name}' has no section assigned."
                ], 422);
            }

            $subjects = $class->section->subjects?->pluck('name')->filter()->unique();

            if (!$subjects || $subjects->isEmpty()) {
                return response()->json([
                    'message' => "No subjects found for section '{$class->section->name}' in class '{$class->name}'."
                ], 404);
            }

            $totalSlots = count($days) * $periodsPerDay;
            $freePeriods = 1;
            $requiredSlots = $totalSlots - $freePeriods;
            $subjectCount = $subjects->count();

            $baseCount = intdiv($requiredSlots, $subjectCount);
            $remainder = $requiredSlots % $subjectCount;

            $subjectPool = collect();
            foreach ($subjects as $i => $subject) {
                $count = $baseCount + ($i < $remainder ? 1 : 0);
                $subjectPool = $subjectPool->merge(array_fill(0, $count, $subject));
            }

            $subjectPool = $subjectPool->shuffle();
            $freeIndex = rand(1, $totalSlots);
            $slotIndex = 0;

            foreach ($days as $day) {
                $usedSubjectsForDay = [];

                for ($period = 1; $period <= $periodsPerDay; $period++) {
                    $slotIndex++;

                    if ($slotIndex === $freeIndex) {
                        $subject = 'Free Period';
                    } else {
                        $attempts = 0;
                        do {
                            $subject = $subjectPool->shift();
                            $attempts++;

                            if (!$subject) {
                                $subjectPool = $subjects->shuffle();
                                $subject = $subjectPool->shift();
                            }

                            if ($attempts > 10) break;
                        } while (in_array($subject, $usedSubjectsForDay));

                        if ($subject !== 'Free Period') {
                            $usedSubjectsForDay[] = $subject;
                        }
                    }

                    Timetable::create([
                        'class_id' => $class->id,
                        'day' => $day,
                        'period_number' => $period,
                        'subject' => $subject,
                        'school_id' => $schoolId,
                    ]);
                }
            }
        }

        DB::commit();

        $summary = Timetable::where('school_id', $schoolId)
            ->select('subject', DB::raw('COUNT(*) as total'))
            ->groupBy('subject')
            ->orderBy('total', 'desc')
            ->get();

        return response()->json([
            'message' => 'Timetable generated successfully by section-based subjects.',
            'data' => $this->formatTimetableResponse($schoolId),
            'summary' => $summary,
        ]);

    } catch (\Exception $e) {
        DB::rollBack();

        return response()->json([
            'message' => 'Error generating timetable.',
            'error' => $e->getMessage(),
        ], 500);
    }
}





    /**
     * ✅ Get the most recently generated timetable for a school
     */
    public function getRecentTimetable()
    {
        $schoolId = Auth::user()->school_id;

        $recent = Timetable::where('school_id', $schoolId)
            ->orderBy('created_at', 'desc')
            ->exists();

        if (!$recent) {
            return response()->json(['message' => 'No timetable found.'], 404);
        }

        return response()->json([
            'message' => 'Recent timetable retrieved successfully.',
            'data' => $this->formatTimetableResponse($schoolId),
        ]);
    }

    /**
     * ✅ Format timetable grouped by class and day
     */
    private function formatTimetableResponse($schoolId = null)
    {
        $classes = StudentClass::with(['timetables' => function ($query) {
            $query->orderBy('period_number');
        }])
            ->when($schoolId, fn($q) => $q->where('school_id', $schoolId))
            ->get();

        return $classes->map(function ($class) {
            return [
                'class_name' => $class->name,
                'timetable' => $class->timetables->groupBy('day')->map(function ($items) {
                    return $items->map(function ($row) {
                        return [
                            'period_number' => $row->period_number,
                            'subject' => $row->subject,
                        ];
                    })->values();
                }),
            ];
        });
    }
}
