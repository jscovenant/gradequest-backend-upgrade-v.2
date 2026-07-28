<?php

// app/Repositories/ResultRepository.php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * ResultRepository
 *
 * This repository centralizes all database interactions
 * related to:
 *   - Result batches
 *   - Student result headers (student_results_v2)
 *   - Subject result rows (subject_results_v2)
 *   - Legacy result tables
 *
 * Keeping DB logic here:
 *   ✔ Keeps controllers clean
 *   ✔ Makes business logic easier to maintain
 *   ✔ Allows easier refactoring later (e.g., switch to Eloquent)
 */
class ResultRepository
{
  /**
   * Find a result batch using:
   *  - school_id
   *  - class_id
   *  - term
   *  - session
   *
   * Returns the first matching batch or null.
   */
  public function findBatch(
    int $schoolId,
    int $classId,
    string $term,
    string $session
  ): ?object {
    return DB::table('result_batches')
      ->where('school_id', $schoolId)
      ->where('class_id', $classId)
      ->where('term', $term)
      ->where('session', $session)
      ->first();
  }

  /**
   * Resolve (or create) a batch.
   *
   * Logic:
   *   1. Try to find existing batch.
   *   2. If found → return it.
   *   3. If not found → create new batch with status "draft".
   */
  public function resolveBatch(
    int $schoolId,
    int $classId,
    string $term,
    string $session,
    ?int $createdBy
  ): object {

    // Check if batch already exists
    $batch = $this->findBatch($schoolId, $classId, $term, $session);

    if ($batch) {
      return $batch;
    }

    // Create new batch
    $id = DB::table('result_batches')->insertGetId([
      'school_id' => $schoolId,
      'class_id' => $classId,
      'term' => $term,
      'session' => $session,
      'status' => 'draft',
      'created_by' => $createdBy,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return DB::table('result_batches')->where('id', $id)->first();
  }

  /**
   * Get the V2 student result header.
   *
   * student_results_v2 is the "master" record containing:
   *   - summary fields
   *   - total average
   *   - class position
   *   - comments
   */
  public function getV2StudentResult(
    int $batchId,
    int $studentId
  ): ?object {
    return DB::table('student_results_v2')
      ->where('batch_id', $batchId)
      ->where('user_id', $studentId)
      ->first();
  }

  /**
   * Get subject result rows for a student_result.
   *
   * This fetches:
   *   - subject_id
   *   - CA raw JSON
   *   - exam
   *   - total
   *   - grade
   *   - carry_over JSON
   *
   * Used when preparing the result form.
   */
  public function getV2StudentResultRows(int $studentResultId)
  {
    return DB::table('subject_results_v2')
      ->where('student_result_id', $studentResultId)
      ->select([
        'subject_id',
        'ca_raw',
        'exam',
        'total',
        'grade',
        'remark',
        'carry_over_json',
      ])
      ->get();
  }

  /**
   * Get full subject results including assessment breakdown.
   *
   * This method:
   *   1. Fetches subject_results_v2 rows
   *   2. For each subject, loads assessment_scores_v2
   *
   * Used when showing detailed result breakdown.
   */
  // public function getV2SubjectResults(int $studentResultId): array
  // {
  //   $subs = DB::table('subject_results_v2')
  //     ->where('student_result_id', $studentResultId)
  //     ->get()
  //     ->toArray();

  //   foreach ($subs as $s) {

  //     // Attach CA components from assessment_scores_v2
  //     $s->assessment_scores = DB::table('assessment_scores_v2')
  //       ->where('subject_result_id', $s->id)
  //       ->get()
  //       ->toArray();
  //   }

  //   return $subs;
  // }

public function getV2SubjectResults(int $studentResultId): array
{
    $rows = DB::table('subject_results_v2 as sr')
        ->join('subjects as s', 'sr.subject_id', '=', 's.id')
        ->where('sr.student_result_id', $studentResultId)
        ->select(
            'sr.id',
            'sr.student_result_id',
            'sr.subject_id',
            's.name as subject_name',

            'sr.ca_raw as ca',
            'sr.ca_total',
            'sr.exam',
            'sr.total',
            'sr.grade',
            'sr.remark',
            'sr.subject_position',
            'sr.comment',
            'sr.carry_over_enabled',
            'sr.cumulative_total',
            'sr.cumulative_average',
            'sr.carry_over_json'
        )
        ->get();

    // Optional: decode JSON to an object the frontend can use directly
    return $rows->map(function ($row) {
        $row->carry_over = $row->carry_over_json
            ? json_decode($row->carry_over_json, true)
            : null;

        unset($row->carry_over_json);

        return $row;
    })->toArray();
}


  /**
   * Legacy fallback method.
   *
   * Used when:
   *   - No V2 batch exists
   *   - Or no V2 student_result found
   *
   * Legacy structure:
   *   averages table → contains summary
   *   first_term_results / second_term_results / third_term_results → subjects
   */
  public function getLegacyReportCard(
    int $schoolId,
    int $classId,
    int $studentId,
    string $term,
    string $session
  ): ?array {

    // Fetch summary (averages table)
    $avg = DB::table('averages')
      ->where('school_id', $schoolId)
      ->where('class_id', $classId)
      ->where('user_id', $studentId)
      ->where('term', $term)
      ->where('session', $session)
      ->first();

    if (!$avg) {
      return null;
    }

    // Determine correct legacy subject table
    $table = $this->termTable($term);

    // Fetch subject rows from legacy table
    $subjects = DB::table($table)
      ->where('average_id', $avg->id)
      ->get()
      ->toArray();

    return [
      'average' => $avg,
      'subjects' => $subjects
    ];
  }

  /**
   * Determine legacy subject table name based on term string.
   *
   * Example:
   *   "First Term"  → first_term_results
   *   "Second Term" → second_term_results
   *   Otherwise     → third_term_results
   */
  private function termTable(string $term): string
  {
    $t = strtolower($term);

    if (str_contains($t, 'first')) {
      return 'first_term_results';
    }

    if (str_contains($t, 'second')) {
      return 'second_term_results';
    }

    return 'third_term_results';
  }


      private function getRatingLabel(int $rate): string
    {
        $ratings = [
            1 => 'Poor',
            2 => 'Fair',
            3 => 'Good',
            4 => 'Very Good',
            5 => 'Excellent'
        ];

        return $ratings[$rate] ?? 'Not Rated';
    }

    public function getAffectiveDomains(int $userId, int $schoolId): array
    {
        return $this->fetchDomains(
            'user_has_affective_domains',
            'affective_domains',
            'affective_id',
            $userId,
            $schoolId
        );
    }

    public function getPsychomotorDomains(int $userId, int $schoolId): array
    {
        return $this->fetchDomains(
            'user_has_psychomotor_domains',
            'psychomotor_domains',
            'psychomotor_id',
            $userId,
            $schoolId
        );
    }

    /**
     * Generic method to fetch domains (affective or psychomotor)
     *
     * @param string $userHasTable
     * @param string $domainTable
     * @param string $foreignKey
     * @param int $userId
     * @param int $schoolId
     * @return array
     */
    private function fetchDomains(
        string $userHasTable,
        string $domainTable,
        string $foreignKey,
        int $userId,
        int $schoolId
    ): array {
        return DB::table("{$userHasTable} as uh")
            ->join("{$domainTable} as d", "uh.{$foreignKey}", '=', 'd.id')
            ->where('uh.user_id', $userId)
            ->where('uh.school_id', $schoolId)
            ->orderByDesc('uh.updated_at')
            ->select('d.title as domain', 'uh.rate')
            ->get()
            ->unique('domain')
            ->values()
            ->map(fn($record) => [
                'domain' => $record->domain,
                'rating' => $this->getRatingLabel($record->rate),
            ])
            ->toArray();
    }
}
