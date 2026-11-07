<?php

namespace App\Services;

use App\Models\CbtAttempt;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CbtAccessService
{
    public function activeEditionTier(int $schoolId): string
    {
        if ($schoolId <= 0) {
            return 'basic_result';
        }

        $policy = app(SchoolFeeAccessPolicyService::class)->policyForSchool($schoolId);
        $tier = strtolower(trim((string) ($policy['active_edition_tier'] ?? 'standard_cbt')));

        return in_array($tier, ['basic_result', 'standard_cbt', 'annual_full_session'], true)
            ? $tier
            : 'standard_cbt';
    }

    public function hasFullCbtPackage(int $schoolId): bool
    {
        if ($schoolId <= 0) {
            return false;
        }

        return $this->activeEditionTier($schoolId) !== 'basic_result';
    }

    public function ensureCanUse(User $user, string $mode = 'online'): void
    {
        $schoolId = (int) ($user->school_id ?? 0);
        if ($schoolId > 0 && ! $this->hasFullCbtPackage($schoolId)) {
            abort(403, 'CBT Examination is not included in the Basic Result Edition. Please upgrade to the Full CBT & AI Edition to access CBT exams.');
        }

        if (strtolower((string) $user->role) === 'student') {
            if ($user->level_id && app(SchoolBillingService::class)->isClassCbtDisabled($schoolId, (int) $user->level_id)) {
                $className = $user->level?->name ?? 'your class';
                abort(403, "CBT examination access is disabled for {$className} under the school package configuration.");
            }

            $clearance = app(SchoolBillingService::class)->studentAcademicClearanceStatus($schoolId, (int) $user->id);
            if (! $clearance['allowed']) {
                abort(403, 'CBT Exam Access Locked: Term fee clearance is required for this student. Please contact the school administration.');
            }
        }
    }

    /**
     * Retrieve objective CBT scores for a student across subjects for a given class, term, and session.
     * Enforces subscription-based access control: returns empty array if school does NOT have Full CBT Package.
     *
     * @param  Collection|array  $subjects
     * @return array<int, array<string, mixed>>
     */
    public function studentObjectiveScoresForBatch(
        int $schoolId,
        User $student,
        ?int $classId,
        ?string $termName,
        ?string $sessionName,
        $subjects
    ): array {
        if (! $this->hasFullCbtPackage($schoolId)) {
            return [];
        }

        $subjectsCollection = collect($subjects);
        if ($subjectsCollection->isEmpty()) {
            return [];
        }

        $termVariants = $this->termNameVariants((string) $termName);
        $termIds = $termVariants !== []
            ? DB::table('terms')
                ->where('school_id', $schoolId)
                ->whereIn(DB::raw('LOWER(TRIM(name))'), $termVariants)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];
        if ($termVariants !== [] && empty($termIds)) {
            return [];
        }

        $normalizedSession = strtolower(trim((string) $sessionName));
        $sessionIds = $normalizedSession !== ''
            ? DB::table('academic_sessions')
                ->where('school_id', $schoolId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedSession])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];
        if ($normalizedSession !== '' && empty($sessionIds)) {
            return [];
        }

        $effectiveClassId = $classId ?: ($student->level_id ? (int) $student->level_id : null);

        $attempts = CbtAttempt::query()
            ->with([
                'exam.subject:id,name',
                'exam.questions:id,exam_id,question_type,marks',
                'answers.question:id,exam_id,question_type,marks',
            ])
            ->where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->whereHas('exam', function ($q) use ($schoolId, $effectiveClassId, $termIds, $sessionIds) {
                $q->where('school_id', $schoolId);
                if ($effectiveClassId) {
                    $q->where(function ($sub) use ($effectiveClassId) {
                        $sub->whereNull('class_id')->orWhere('class_id', $effectiveClassId);
                    });
                }
                if ($termIds !== []) {
                    $q->where(function ($sub) use ($termIds) {
                        $sub->whereIn('term_id', $termIds);
                    });
                }
                if ($sessionIds !== []) {
                    $q->where(function ($sub) use ($sessionIds) {
                        $sub->whereIn('academic_session_id', $sessionIds);
                    });
                }
            })
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        if ($attempts->isEmpty()) {
            return [];
        }

        $result = [];

        foreach ($subjectsCollection as $subj) {
            $subjectId = (int) (is_array($subj) ? ($subj['id'] ?? 0) : ($subj->id ?? 0));
            $subjectName = strtolower(trim((string) (is_array($subj) ? ($subj['name'] ?? '') : ($subj->name ?? ''))));
            if ($subjectId <= 0) {
                continue;
            }

            $matchingAttempts = $attempts->filter(function (CbtAttempt $att) use ($subjectId, $subjectName) {
                $exam = $att->exam;
                if (! $exam) {
                    return false;
                }
                if ((int) ($exam->subject_id ?? 0) === $subjectId) {
                    return true;
                }
                $examSubjectName = strtolower(trim((string) ($exam->subject?->name ?? '')));
                return $subjectName !== '' && $examSubjectName !== '' && $examSubjectName === $subjectName;
            });

            if ($matchingAttempts->isEmpty()) {
                continue;
            }

            // Prefer attempts where the exam explicitly matched the active term/session over null term/session
            $sortedAttempts = $matchingAttempts->sortByDesc(function (CbtAttempt $att) use ($termIds, $sessionIds, $subjectId) {
                $exam = $att->exam;
                $specificity = 0;
                if ((int) ($exam?->subject_id ?? 0) === $subjectId) {
                    $specificity += 4;
                }
                if ($termIds !== [] && in_array((int) ($exam?->term_id ?? 0), $termIds, true)) {
                    $specificity += 2;
                }
                if ($sessionIds !== [] && in_array((int) ($exam?->academic_session_id ?? 0), $sessionIds, true)) {
                    $specificity += 1;
                }
                $ts = $att->submitted_at ? $att->submitted_at->getTimestamp() : ($att->started_at ? $att->started_at->getTimestamp() : 0);
                return ($specificity * 10000000000) + $ts;
            });

            $completedAttempt = $sortedAttempts->first(function (CbtAttempt $att) {
                $meta = is_array($att->metadata) ? $att->metadata : [];
                return in_array($att->status, ['submitted', 'auto_submitted', 'section_a_completed'], true)
                    || ! empty($meta['section_a_completed']);
            });

            if ($completedAttempt) {
                $exam = $completedAttempt->exam;
                $objectiveMaxMarks = $exam
                    ? (float) $exam->questions->where('question_type', '!=', 'theory')->sum('marks')
                    : (float) $completedAttempt->total_marks;

                // Calculate objective score strictly from non-theory questions
                $answersCollection = $completedAttempt->answers ?? collect();
                if ($answersCollection->isNotEmpty()) {
                    $objectiveScore = (float) $answersCollection
                        ->filter(fn ($ans) => ($ans->question?->question_type ?? 'single_choice') !== 'theory')
                        ->sum('score');
                } else {
                    $objectiveScore = (float) $completedAttempt->score;
                }

                $result[$subjectId] = [
                    'subject_id' => $subjectId,
                    'subject_name' => is_array($subj) ? ($subj['name'] ?? '') : ($subj->name ?? ''),
                    'available' => true,
                    'has_attempt' => true,
                    'status' => 'completed',
                    'score' => round($objectiveScore, 2),
                    'max_marks' => round($objectiveMaxMarks, 2),
                    'exam_id' => (int) $completedAttempt->exam_id,
                    'exam_title' => (string) ($exam?->title ?? 'CBT Exam'),
                    'attempt_id' => (int) $completedAttempt->id,
                    'submitted_at' => optional($completedAttempt->submitted_at)->toIso8601String(),
                ];
            } else {
                $incompleteAttempt = $sortedAttempts->first();
                if ($incompleteAttempt) {
                    $exam = $incompleteAttempt->exam;
                    $objectiveMaxMarks = $exam
                        ? (float) $exam->questions->where('question_type', '!=', 'theory')->sum('marks')
                        : (float) $incompleteAttempt->total_marks;

                    $result[$subjectId] = [
                        'subject_id' => $subjectId,
                        'subject_name' => is_array($subj) ? ($subj['name'] ?? '') : ($subj->name ?? ''),
                    'available' => false,
                    'has_attempt' => false,
                    'incomplete_attempt' => true,
                        'status' => 'incomplete',
                        'score' => null,
                        'max_marks' => round($objectiveMaxMarks, 2),
                        'exam_id' => (int) $incompleteAttempt->exam_id,
                        'exam_title' => (string) ($exam?->title ?? 'CBT Exam'),
                        'attempt_id' => (int) $incompleteAttempt->id,
                        'submitted_at' => null,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Resolve the school's configured grading scale (section-specific or school default),
     * falling back to standard grading rules if none are configured in grading_scales.
     *
     * @return array<int, array{min_score: float, max_score: float, grade: string, remark: string}>
     */
    public function resolveGradingScale(int $schoolId, ?int $sectionId = null): array
    {
        $rows = collect();

        if ($schoolId > 0 && $sectionId) {
            $rows = DB::table('grading_scales')
                ->where('school_id', $schoolId)
                ->where('section_id', $sectionId)
                ->orderByRaw('CAST(min AS DECIMAL(8,2)) DESC')
                ->get();
        }

        if ($rows->isEmpty() && $schoolId > 0) {
            $rows = DB::table('grading_scales')
                ->where('school_id', $schoolId)
                ->whereNull('section_id')
                ->orderByRaw('CAST(min AS DECIMAL(8,2)) DESC')
                ->get();
        }

        if ($rows->isEmpty() && $schoolId > 0) {
            $rows = DB::table('grading_scales')
                ->where('school_id', $schoolId)
                ->orderByRaw('CAST(min AS DECIMAL(8,2)) DESC')
                ->get();
        }

        if ($rows->isNotEmpty()) {
            return $rows->map(fn ($r) => [
                'min_score' => (float) ($r->min ?? $r->min_score ?? 0),
                'max_score' => (float) ($r->max ?? $r->max_score ?? 100),
                'grade' => (string) ($r->grade ?? ''),
                'remark' => (string) ($r->remark ?? ''),
            ])->values()->all();
        }

        return [
            ['min_score' => 70, 'max_score' => 100, 'grade' => 'A', 'remark' => 'Excellent'],
            ['min_score' => 60, 'max_score' => 69.99, 'grade' => 'B', 'remark' => 'Very Good'],
            ['min_score' => 50, 'max_score' => 59.99, 'grade' => 'C', 'remark' => 'Credit'],
            ['min_score' => 40, 'max_score' => 49.99, 'grade' => 'D', 'remark' => 'Pass'],
            ['min_score' => 0, 'max_score' => 39.99, 'grade' => 'F', 'remark' => 'Fail'],
        ];
    }

    /**
     * Calculate grade and remark for a numeric total score using the school's grading scale.
     *
     * @return array{grade: ?string, remark: ?string}
     */
    public function gradeForScore(?float $score, array $gradingScale): array
    {
        if ($score === null) {
            return ['grade' => null, 'remark' => null];
        }

        foreach ($gradingScale as $row) {
            $min = (float) ($row['min_score'] ?? 0);
            $max = (float) ($row['max_score'] ?? 100);
            if ($score >= $min && $score <= $max) {
                return [
                    'grade' => (string) ($row['grade'] ?? ''),
                    'remark' => (string) ($row['remark'] ?? ''),
                ];
            }
        }

        // Handle edge scores slightly between integer boundaries (e.g. 69.5 when scale is 60-69 and 70-100)
        $sorted = collect($gradingScale)->sortByDesc('min_score')->values();
        foreach ($sorted as $row) {
            if ($score >= (float) ($row['min_score'] ?? 0)) {
                return [
                    'grade' => (string) ($row['grade'] ?? ''),
                    'remark' => (string) ($row['remark'] ?? ''),
                ];
            }
        }

        return ['grade' => 'F', 'remark' => 'Fail'];
    }

    /**
     * @return array<int, string>
     */
    private function termNameVariants(string $termName): array
    {
        $clean = strtolower(trim($termName));
        if ($clean === '') {
            return [];
        }

        if (str_contains($clean, 'first') || str_contains($clean, '1st')) {
            return ['first term', '1st term', 'first', 'term 1'];
        }
        if (str_contains($clean, 'second') || str_contains($clean, '2nd')) {
            return ['second term', '2nd term', 'second', 'term 2'];
        }
        if (str_contains($clean, 'third') || str_contains($clean, '3rd')) {
            return ['third term', '3rd term', 'third', 'term 3'];
        }

        return [$clean];
    }

    /**
     * Auto-save CBT objective score to student report card (subject_results_v2).
     * Strictly binds to the exam's designated academic session, term, class, and subject.
     */
    public function saveObjectiveScoreToReportCard(CbtExam $exam, User $student, float $objectiveScore): void
    {
        try {
            $exam->loadMissing(['term', 'academicSession']);
            $schoolId = (int) $exam->school_id;
            $classId = (int) ($exam->class_id ?: $student->level_id);
            $subjectId = (int) $exam->subject_id;
            if (!$subjectId || !$classId || !$schoolId) {
                return;
            }

            // Ensure academic session and term are resolved
            $sessionId = $exam->academic_session_id;
            $termId = $exam->term_id;

            if (!$sessionId) {
                $sessionId = DB::table('academic_sessions')
                    ->where('school_id', $schoolId)
                    ->whereNull('archived_at')
                    ->where('is_current', 1)
                    ->value('id') ?: DB::table('academic_sessions')
                    ->where('school_id', $schoolId)
                    ->whereNull('archived_at')
                    ->orderByDesc('id')
                    ->value('id');
            }

            if (!$termId) {
                $termId = DB::table('terms')
                    ->where('school_id', $schoolId)
                    ->whereNull('archived_at')
                    ->where(function ($q) {
                        $q->where('status', 'Active')->orWhere('status', 'active');
                    })
                    ->orderByRaw('COALESCE(sort_order, 999999) ASC')
                    ->orderBy('id')
                    ->value('id') ?: DB::table('terms')
                    ->where('school_id', $schoolId)
                    ->whereNull('archived_at')
                    ->orderBy('id')
                    ->value('id');
            }

            $sessionName = $exam->academicSession?->name ?: DB::table('academic_sessions')->where('id', $sessionId)->value('name');
            $termName = $exam->term?->name ?: DB::table('terms')->where('id', $termId)->value('name');

            if (!$sessionName || !$termName) {
                return;
            }

            // Find or create result batch strictly for this session and term
            $batch = \App\Models\ResultBatch::where('school_id', $schoolId)
                ->where('class_id', $classId)
                ->where('term', $termName)
                ->where('session', $sessionName)
                ->first();

            if (!$batch) {
                $batch = \App\Models\ResultBatch::create([
                    'school_id' => $schoolId,
                    'class_id' => $classId,
                    'term' => $termName,
                    'session' => $sessionName,
                    'status' => 'draft',
                ]);
            }

            // Find or create student result header
            $studentResult = DB::table('student_results_v2')
                ->where('batch_id', $batch->id)
                ->where('user_id', $student->id)
                ->first();

            if (!$studentResult) {
                $studentResultId = DB::table('student_results_v2')->insertGetId([
                    'batch_id' => $batch->id,
                    'user_id' => $student->id,
                    'rollno' => $student->reg_no,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $studentResultId = $studentResult->id;
            }

            // Find or create subject result row
            $existing = DB::table('subject_results_v2')
                ->where('student_result_id', $studentResultId)
                ->where('subject_id', $subjectId)
                ->first();

            $savedTheory = ($existing && property_exists($existing, 'theory') && $existing->theory !== null)
                ? (float) $existing->theory
                : null;

            $examScore = round($objectiveScore + (float) ($savedTheory ?? 0), 2);
            $caTotal = ($existing && property_exists($existing, 'ca_total') && $existing->ca_total !== null)
                ? (float) $existing->ca_total
                : 0.0;

            $hasCa = ($existing && $existing->ca_total !== null);
            $total = ($hasCa || $examScore !== null) ? round($caTotal + $examScore, 2) : null;

            // Resolve grading scale
            $sectionId = $student->section_id ?: ($student->level?->section_id ?? null);
            $gradingScale = $this->resolveGradingScale($schoolId, $sectionId ? (int) $sectionId : null);
            $gradeRemark = $this->gradeForScore($total, $gradingScale);

            $payload = [
                'cbt_obj' => round($objectiveScore, 2),
                'exam' => $examScore,
                'total' => $total,
                'grade' => $gradeRemark['grade'] ?? null,
                'remark' => $gradeRemark['remark'] ?? null,
                'updated_at' => now(),
            ];

            if (!$existing) {
                $payload['student_result_id'] = $studentResultId;
                $payload['subject_id'] = $subjectId;
                $payload['created_at'] = now();
                DB::table('subject_results_v2')->insert($payload);
            } else {
                DB::table('subject_results_v2')->where('id', $existing->id)->update($payload);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Failed to auto-save CBT OBJ to student report card: " . $e->getMessage());
        }
    }
}