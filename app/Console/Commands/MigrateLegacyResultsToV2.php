<?php

// app/Console/Commands/MigrateLegacyResultsToV2.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyResultsToV2 extends Command
{
  protected $signature = 'results:migrate-legacy {--chunk=500} {--school_id=}';
  protected $description = 'Migrate legacy averages + term result tables into v2 tables (idempotent)';

  public function handle(): int
  {
    $chunk = (int)$this->option('chunk') ?: 500;
    $schoolId = $this->option('school_id');

    $query = DB::table('averages')->orderBy('id');

    if ($schoolId) $query->where('school_id', $schoolId);

    $this->info("Migrating legacy results…");

    $query->chunkById($chunk, function ($rows) {
      foreach ($rows as $avg) {

          if (empty($avg->school_id)) {
    $this->warn("Skipping averages.id={$avg->id} because school_id is NULL");
    continue;
  }
        DB::transaction(function () use ($avg) {

          // 1) Resolve/Create Batch
          $batchId = DB::table('result_batches')->where([
            'school_id' => $avg->school_id,
            'class_id'  => $avg->class_id,
            'term'      => $avg->term,
            'session'   => $avg->session,
          ])->value('id');

          if (!$batchId) {
            $batchId = DB::table('result_batches')->insertGetId([
              'school_id'  => $avg->school_id,
              'class_id'   => $avg->class_id,
              'term'       => $avg->term,
              'session'    => $avg->session,
              'status'     => 'draft',
              'created_by' => null,
              'created_at' => now(),
              'updated_at' => now(),
            ]);
          }

          // 2) Upsert student_results_v2 (idempotent)
          $studentResultId = DB::table('student_results_v2')
            ->where('average_legacy_id', $avg->id)
            ->value('id');

          if (!$studentResultId) {
            $studentResultId = DB::table('student_results_v2')->insertGetId([
              'batch_id'               => $batchId,
              'user_id'                => $avg->user_id,
              'average_legacy_id'      => $avg->id,
              'rollno'                 => $avg->rollno,
              'department'             => $avg->department,
              'section_id'             => $avg->section_id,
              'position'               => $avg->position,
              'class_teacher'          => $avg->class_teacher,
              'class_size'             => $avg->class_size,
              'total_grade'            => $avg->total_grade,
              'total_average'          => $avg->total_average,
              'principal_comment'      => $avg->principal_comment,
              'class_teacher_comment'  => $avg->class_teacher_comment,
              'general_remark'         => $avg->general_remark,
              'meta_json'              => json_encode([
                'resumption_date' => $avg->resumption_date,
                'school_open'     => $avg->school_open,
                'school_close'    => $avg->school_close,
                'no_present'      => $avg->no_present,
                'no_absent'       => $avg->no_absent,
              ]),
              'created_at' => $avg->created_at ?? now(),
              'updated_at' => $avg->updated_at ?? now(),
            ]);
          }

          // 3) Determine term table
          $term = strtolower((string)$avg->term);
          $table = str_contains($term, 'first') ? 'first_term_results'
                 : (str_contains($term, 'second') ? 'second_term_results'
                 : 'third_term_results');

          // 4) Pull subject rows from legacy table by average_id
          $legacyRows = DB::table($table)->where('average_id', $avg->id)->get();

          foreach ($legacyRows as $lr) {
            // upsert subject result
            $exists = DB::table('subject_results_v2')
              ->where('student_result_id', $studentResultId)
              ->where('subject_id', $lr->subject_id)
              ->exists();

            if ($exists) continue;

            $carry = null;
            if ($table === 'second_term_results') {
              $carry = ['firstterm' => $lr->firstterm, 'average' => $lr->average];
            }
            if ($table === 'third_term_results') {
              $carry = ['firstterm' => $lr->firstterm, 'secondterm' => $lr->secondterm, 'average' => $lr->average];
            }

            $subjectResultId = DB::table('subject_results_v2')->insertGetId([
              'student_result_id' => $studentResultId,
              'subject_id'        => $lr->subject_id,
              'legacy_table'      => $table,
              'legacy_id'         => $lr->id,
              'ca_raw'            => $lr->ca,
              'exam'              => $lr->exam,
              'total'             => $lr->total,
              'grade'             => $lr->grade,
              'remark'            => $lr->remark,
              'comment'           => $lr->comment,
              'signature'         => $lr->signature,
              'carry_over_json'   => $carry ? json_encode($carry) : null,
              'created_at'        => $lr->created_at ?? now(),
              'updated_at'        => $lr->updated_at ?? now(),
            ]);

            // 5) Parse CA JSON into assessment_scores_v2 (best-effort)
            $this->migrateCaComponents($subjectResultId, $lr->ca);
          }
        });
      }
    });

    $this->info("✅ Done.");
    return self::SUCCESS;
  }

  private function migrateCaComponents(int $subjectResultId, $caRaw): void
  {
    if (!$caRaw) return;

    // CA sometimes stored as JSON string like {"ca0":10,"ca1":8} OR {"0":10,"1":8}
    $decoded = json_decode($caRaw, true);
    if (!is_array($decoded)) return;

    foreach ($decoded as $key => $val) {
      $componentKey = is_numeric($key) ? "ca{$key}" : (string)$key;

      DB::table('assessment_scores_v2')->updateOrInsert(
        ['subject_result_id' => $subjectResultId, 'component_key' => $componentKey],
        ['score' => (float)($val ?? 0), 'updated_at' => now(), 'created_at' => now()]
      );
    }
  }
}
