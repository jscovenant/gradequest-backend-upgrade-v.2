<?php

namespace App\Services\Results;

class ResultComputeService
{
  public function computeBatch(int $batchId): void
  {
    // next step:
    // - compute totals from assessment_scores_v2 + exam
    // - compute averages per student in student_results_v2
    // - rank positions for the batch
    // - resolve grades using grading_for_juniors/seniors
    // Queue this in production.
  }
}
