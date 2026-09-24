<?php

namespace App\Console\Commands;

use App\Models\Subject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeduplicateSchoolSubjectsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schoolprofit:deduplicate-subjects 
                            {--school_id= : Specific school ID to deduplicate (optional)}
                            {--dry-run : Run simulation without committing any database changes}
                            {--force : Force execution without confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely deduplicates subjects per school and re-links all result records, enrollments, teacher assignments, and lesson plans without data loss.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $schoolId = $this->option('school_id') ? (int) $this->option('school_id') : null;
        $isDryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info("==========================================================");
        $this->info("  GRADEQUEST / SCHOOLPROFIT SUBJECT DEDUPLICATION SYSTEM  ");
        $this->info("==========================================================");
        $this->info("Mode: " . ($isDryRun ? "DRY RUN (Simulation Only - No changes saved)" : "LIVE EXECUTION"));
        if ($schoolId) {
            $this->info("Target School ID: {$schoolId}");
        } else {
            $this->info("Target: ALL Registered Schools");
        }

        // 1. Find all duplicate subject groups
        $query = DB::table('subjects')
            ->select(
                'school_id',
                DB::raw('LOWER(TRIM(name)) as clean_name'),
                DB::raw('COUNT(*) as total_count'),
                DB::raw('GROUP_CONCAT(id ORDER BY id ASC) as subject_ids')
            )
            ->whereNull('archived_at');

        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        $duplicates = $query
            ->groupBy('school_id', DB::raw('LOWER(TRIM(name))'))
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('school_id')
            ->orderBy('clean_name')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info("No duplicate subjects found! All subjects are already unique.");
            return Command::SUCCESS;
        }

        $this->warn("Found " . $duplicates->count() . " duplicate subject groups to resolve.");

        if (! $isDryRun && ! $force) {
            if (! $this->confirm("Are you sure you want to proceed with merging duplicate subjects and re-pointing all results?", false)) {
                $this->info("Operation cancelled by user.");
                return Command::SUCCESS;
            }
        }

        // Stats tracking
        $stats = [
            'duplicate_groups' => $duplicates->count(),
            'subjects_removed' => 0,
            'first_term_results' => 0,
            'second_term_results' => 0,
            'third_term_results' => 0,
            'subject_results_v2' => 0,
            'subject_enrolls' => 0,
            'teacher_subjects' => 0,
            'cbt_exams' => 0,
            'quizzes' => 0,
            'lesson_schemes' => 0,
            'generated_lesson_plans' => 0,
            'lesson_notes' => 0,
            'lesson_plans' => 0,
            'academic_alerts' => 0,
            'subject_offerings' => 0,
            'student_subject_overrides' => 0,
        ];

        $summaryRows = [];

        DB::beginTransaction();

        try {
            foreach ($duplicates as $group) {
                $subjectIds = array_map('intval', explode(',', $group->subject_ids));
                
                // Fetch all subject records in this group
                $subjectRows = DB::table('subjects')
                    ->whereIn('id', $subjectIds)
                    ->get();

                // Determine Canonical Subject:
                // Prefer:
                // 1. One that is already general (department_id is null)
                // 2. One that has the most result records
                // 3. Lowest ID (oldest)
                $scoredSubjects = $subjectRows->map(function ($subj) {
                    $resultsCount = 0;
                    if (Schema::hasTable('first_term_results')) {
                        $resultsCount += DB::table('first_term_results')->where('subject_id', $subj->id)->count();
                    }
                    if (Schema::hasTable('second_term_results')) {
                        $resultsCount += DB::table('second_term_results')->where('subject_id', $subj->id)->count();
                    }
                    if (Schema::hasTable('third_term_results')) {
                        $resultsCount += DB::table('third_term_results')->where('subject_id', $subj->id)->count();
                    }
                    if (Schema::hasTable('subject_results_v2')) {
                        $resultsCount += DB::table('subject_results_v2')->where('subject_id', $subj->id)->count();
                    }

                    return [
                        'id' => $subj->id,
                        'name' => $subj->name,
                        'is_general' => is_null($subj->department_id) ? 1 : 0,
                        'results_count' => $resultsCount,
                    ];
                })->sort(function ($a, $b) {
                    if ($a['is_general'] !== $b['is_general']) {
                        return $b['is_general'] <=> $a['is_general']; // General first
                    }
                    if ($a['results_count'] !== $b['results_count']) {
                        return $b['results_count'] <=> $a['results_count']; // Highest results first
                    }
                    return $a['id'] <=> $b['id']; // Lowest ID first
                })->values();

                $canonical = $scoredSubjects->first();
                $canonicalId = (int) $canonical['id'];
                $canonicalName = trim((string) $canonical['name']);

                // Duplicate IDs to merge into canonical
                $dupIds = array_values(array_filter($subjectIds, fn ($id) => $id !== $canonicalId));
                $stats['subjects_removed'] += count($dupIds);

                $groupResultsRelinked = 0;

                // 1. Re-link first_term_results
                if (Schema::hasTable('first_term_results') && ! empty($dupIds)) {
                    $count = DB::table('first_term_results')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['first_term_results'] += $count;
                        $groupResultsRelinked += $count;
                        if (! $isDryRun) {
                            DB::table('first_term_results')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 2. Re-link second_term_results
                if (Schema::hasTable('second_term_results') && ! empty($dupIds)) {
                    $count = DB::table('second_term_results')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['second_term_results'] += $count;
                        $groupResultsRelinked += $count;
                        if (! $isDryRun) {
                            DB::table('second_term_results')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 3. Re-link third_term_results
                if (Schema::hasTable('third_term_results') && ! empty($dupIds)) {
                    $count = DB::table('third_term_results')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['third_term_results'] += $count;
                        $groupResultsRelinked += $count;
                        if (! $isDryRun) {
                            DB::table('third_term_results')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 4. Re-link subject_results_v2 (Safe collision handling on student_result_id + subject_id)
                if (Schema::hasTable('subject_results_v2') && ! empty($dupIds)) {
                    // Check for existing rows where student_result_id already has canonicalId
                    $existingResultIds = DB::table('subject_results_v2')
                        ->where('subject_id', $canonicalId)
                        ->pluck('student_result_id')
                        ->toArray();

                    if (! empty($existingResultIds)) {
                        // If any duplicate row collides with existing canonical row, remove the duplicate row if it has empty score
                        $collidingDups = DB::table('subject_results_v2')
                            ->whereIn('subject_id', $dupIds)
                            ->whereIn('student_result_id', $existingResultIds)
                            ->get();

                        foreach ($collidingDups as $cDup) {
                            if (! $isDryRun) {
                                DB::table('subject_results_v2')->where('id', $cDup->id)->delete();
                            }
                        }
                    }

                    $count = DB::table('subject_results_v2')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['subject_results_v2'] += $count;
                        $groupResultsRelinked += $count;
                        if (! $isDryRun) {
                            DB::table('subject_results_v2')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 5. Re-link subject_enrolls (Safe collision handling on user_id + class_id + school_id)
                if (Schema::hasTable('subject_enrolls') && ! empty($dupIds)) {
                    // Find enrollments where student is already enrolled in canonical subject
                    $canonicalEnrolls = DB::table('subject_enrolls')
                        ->where('school_id', $group->school_id)
                        ->where('subject_id', $canonicalId)
                        ->get(['user_id', 'class_id']);

                    foreach ($canonicalEnrolls as $ce) {
                        if (! $isDryRun) {
                            DB::table('subject_enrolls')
                                ->where('school_id', $group->school_id)
                                ->where('user_id', $ce->user_id)
                                ->where('class_id', $ce->class_id)
                                ->whereIn('subject_id', $dupIds)
                                ->delete();
                        }
                    }

                    $count = DB::table('subject_enrolls')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['subject_enrolls'] += $count;
                        if (! $isDryRun) {
                            DB::table('subject_enrolls')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 6. Re-link teacher_subjects (Safe collision handling on teacher_id + subject_id)
                if (Schema::hasTable('teacher_subjects') && ! empty($dupIds)) {
                    $canonicalTeachers = DB::table('teacher_subjects')
                        ->where('subject_id', $canonicalId)
                        ->pluck('teacher_id')
                        ->toArray();

                    if (! empty($canonicalTeachers)) {
                        if (! $isDryRun) {
                            DB::table('teacher_subjects')
                                ->whereIn('subject_id', $dupIds)
                                ->whereIn('teacher_id', $canonicalTeachers)
                                ->delete();
                        }
                    }

                    $count = DB::table('teacher_subjects')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['teacher_subjects'] += $count;
                        if (! $isDryRun) {
                            DB::table('teacher_subjects')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 7. Re-link cbt_exams
                if (Schema::hasTable('cbt_exams') && ! empty($dupIds)) {
                    $count = DB::table('cbt_exams')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['cbt_exams'] += $count;
                        if (! $isDryRun) {
                            DB::table('cbt_exams')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 8. Re-link quizzes
                if (Schema::hasTable('quizzes') && ! empty($dupIds)) {
                    $count = DB::table('quizzes')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['quizzes'] += $count;
                        if (! $isDryRun) {
                            DB::table('quizzes')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 9. Re-link lesson_schemes
                if (Schema::hasTable('lesson_schemes') && ! empty($dupIds)) {
                    $count = DB::table('lesson_schemes')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['lesson_schemes'] += $count;
                        if (! $isDryRun) {
                            DB::table('lesson_schemes')->whereIn('subject_id', $dupIds)->update([
                                'subject_id' => $canonicalId,
                                'subject' => $canonicalName,
                            ]);
                        }
                    }
                }

                // 10. Re-link generated_lesson_plans
                if (Schema::hasTable('generated_lesson_plans') && ! empty($dupIds)) {
                    $count = DB::table('generated_lesson_plans')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['generated_lesson_plans'] += $count;
                        if (! $isDryRun) {
                            DB::table('generated_lesson_plans')->whereIn('subject_id', $dupIds)->update([
                                'subject_id' => $canonicalId,
                                'subject' => $canonicalName,
                            ]);
                        }
                    }
                }

                // 11. Re-link lesson_notes
                if (Schema::hasTable('lesson_notes') && ! empty($dupIds)) {
                    $count = DB::table('lesson_notes')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['lesson_notes'] += $count;
                        if (! $isDryRun) {
                            DB::table('lesson_notes')->whereIn('subject_id', $dupIds)->update([
                                'subject_id' => $canonicalId,
                                'subject' => $canonicalName,
                            ]);
                        }
                    }
                }

                // 12. Re-link lesson_plans
                if (Schema::hasTable('lesson_plans') && ! empty($dupIds)) {
                    $count = DB::table('lesson_plans')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['lesson_plans'] += $count;
                        if (! $isDryRun) {
                            DB::table('lesson_plans')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 13. Re-link academic_alerts
                if (Schema::hasTable('academic_alerts') && ! empty($dupIds)) {
                    $count = DB::table('academic_alerts')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['academic_alerts'] += $count;
                        if (! $isDryRun) {
                            DB::table('academic_alerts')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 14. Re-link subject_offerings (Safe collision handling)
                if (Schema::hasTable('subject_offerings') && ! empty($dupIds)) {
                    $existingOfferings = DB::table('subject_offerings')
                        ->where('school_id', $group->school_id)
                        ->where('subject_id', $canonicalId)
                        ->get();

                    foreach ($existingOfferings as $eo) {
                        if (! $isDryRun) {
                            DB::table('subject_offerings')
                                ->where('school_id', $group->school_id)
                                ->whereIn('subject_id', $dupIds)
                                ->where('level_id', $eo->level_id)
                                ->where('section_id', $eo->section_id)
                                ->where('department_id', $eo->department_id)
                                ->where('academic_session_id', $eo->academic_session_id)
                                ->where('term_id', $eo->term_id)
                                ->delete();
                        }
                    }

                    $count = DB::table('subject_offerings')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['subject_offerings'] += $count;
                        if (! $isDryRun) {
                            DB::table('subject_offerings')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // 15. Re-link student_subject_overrides (Safe collision handling)
                if (Schema::hasTable('student_subject_overrides') && ! empty($dupIds)) {
                    $existingOverrides = DB::table('student_subject_overrides')
                        ->where('school_id', $group->school_id)
                        ->where('subject_id', $canonicalId)
                        ->get();

                    foreach ($existingOverrides as $eov) {
                        if (! $isDryRun) {
                            DB::table('student_subject_overrides')
                                ->where('school_id', $group->school_id)
                                ->whereIn('subject_id', $dupIds)
                                ->where('student_id', $eov->student_id)
                                ->where('academic_session_id', $eov->academic_session_id)
                                ->where('term_id', $eov->term_id)
                                ->delete();
                        }
                    }

                    $count = DB::table('student_subject_overrides')->whereIn('subject_id', $dupIds)->count();
                    if ($count > 0) {
                        $stats['student_subject_overrides'] += $count;
                        if (! $isDryRun) {
                            DB::table('student_subject_overrides')->whereIn('subject_id', $dupIds)->update(['subject_id' => $canonicalId]);
                        }
                    }
                }

                // Update Canonical Subject: make school-wide/general and clean legacy class/dept pointers
                if (! $isDryRun) {
                    DB::table('subjects')
                        ->where('id', $canonicalId)
                        ->update([
                            'department_id' => null,
                            'class_id' => null,
                            'section_id' => null,
                            'name' => $canonicalName,
                            'updated_at' => now(),
                        ]);

                    // Remove duplicate subject records
                    DB::table('subjects')->whereIn('id', $dupIds)->delete();
                }

                $summaryRows[] = [
                    'school_id' => $group->school_id,
                    'subject' => $canonicalName,
                    'canonical_id' => $canonicalId,
                    'duplicates_removed' => count($dupIds),
                    'results_relinked' => $groupResultsRelinked,
                ];
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->warn("\n[DRY RUN COMPLETE] Rolled back all simulation changes. Database is unchanged.");
            } else {
                DB::commit();
                $this->info("\n[SUCCESS] Deduplication completed and committed successfully!");
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("\n[ERROR] An error occurred during deduplication. All changes have been safely rolled back.");
            $this->error("Error message: " . $e->getMessage());
            $this->error("Trace:\n" . $e->getTraceAsString());
            return Command::FAILURE;
        }

        // Output summary table
        $this->table(
            ['School ID', 'Subject Name', 'Canonical ID', 'Duplicates Merged', 'Results Preserved & Re-linked'],
            array_slice($summaryRows, 0, 50)
        );

        if (count($summaryRows) > 50) {
            $this->info("... and " . (count($summaryRows) - 50) . " more subject groups processed.");
        }

        $this->info("==========================================================");
        $this->info("                    SUMMARY OF ACTIONS                    ");
        $this->info("==========================================================");
        $this->info("Duplicate Subject Groups Merged: {$stats['duplicate_groups']}");
        $this->info("Total Duplicate Subject Rows Removed: {$stats['subjects_removed']}");
        $this->info("1st Term Results Re-linked: {$stats['first_term_results']}");
        $this->info("2nd Term Results Re-linked: {$stats['second_term_results']}");
        $this->info("3rd Term Results Re-linked: {$stats['third_term_results']}");
        $this->info("Subject Results V2 Re-linked: {$stats['subject_results_v2']}");
        $this->info("Subject Enrollments Re-linked: {$stats['subject_enrolls']}");
        $this->info("Teacher Assignments Re-linked: {$stats['teacher_subjects']}");
        $this->info("CBT Exams Re-linked: {$stats['cbt_exams']}");
        $this->info("Quizzes Re-linked: {$stats['quizzes']}");
        $this->info("Lesson Schemes Re-linked: {$stats['lesson_schemes']}");
        $this->info("Lesson Plans / Notes Re-linked: " . ($stats['generated_lesson_plans'] + $stats['lesson_notes'] + $stats['lesson_plans']));
        $this->info("Academic Alerts Re-linked: {$stats['academic_alerts']}");
        $this->info("==========================================================");

        return Command::SUCCESS;
    }
}
