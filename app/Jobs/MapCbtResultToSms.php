<?php

namespace App\Jobs;

use App\Models\CbtResultsInbox;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Result;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Maps an incoming CBT result to the correct SMS student/subject/term record.
 * This is the "zero manual entry" automation step.
 */
class MapCbtResultToSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(private CbtResultsInbox $inbox) {}

    public function handle(): void
    {
        // ── Step 1: Resolve student ──────────────────────────────────────
        $student = $this->resolveStudent();

        if (!$student) {
            $this->inbox->markFailed(
                "Student not found: ref={$this->inbox->cbt_student_ref}"
            );
            Log::warning("CBT result mapping failed — student not found.", [
                'inbox_id' => $this->inbox->id,
                'ref'      => $this->inbox->cbt_student_ref,
            ]);
            return;
        }

        // ── Step 2: Resolve subject ──────────────────────────────────────
        $subject = $this->resolveSubject($student);

        if (!$subject) {
            $this->inbox->markFailed("Subject could not be resolved from exam context.");
            return;
        }

        $this->inbox->markMapped($student->id, $subject->id);

        // ── Step 3: Write/update result record ───────────────────────────
        Result::updateOrCreate(
            [
                'student_id'       => $student->id,
                'subject_id'       => $subject->id,
                'term'             => $this->inbox->term,
                'academic_session' => $this->inbox->academic_session,
                'exam_type'        => $this->inbox->exam_type,
            ],
            [
                'score'            => $this->inbox->score,
                'percentage'       => $this->inbox->percentage,
                'grade'            => $this->inbox->grade,
                'passed'           => $this->inbox->passed,
                'source'           => 'cbt',      // flags as auto-populated
                'cbt_inbox_id'     => $this->inbox->id,
                'posted_at'        => now(),
            ]
        );

        $this->inbox->markPosted();

        Log::info("CBT result posted.", [
            'student'  => $student->name,
            'subject'  => $subject->name,
            'score'    => $this->inbox->score,
            'term'     => $this->inbox->term,
        ]);
    }

    /**
     * Resolve student by matric number (primary) or sms_student_id (fallback).
     */
    private function resolveStudent(): ?Student
    {
        return Student::where('school_id', $this->inbox->school_id)
                      ->where(function ($q) {
                          $q->where('matric_number', $this->inbox->cbt_student_ref)
                            ->orWhere('id', $this->inbox->cbt_student_ref);
                      })
                      ->first();
    }

    /**
     * Resolve subject from the raw payload's subject identifiers.
     */
    private function resolveSubject(Student $student): ?Subject
    {
        $payload = $this->inbox->raw_payload;

        // Try by sms_subject_id first (most reliable)
        if (!empty($payload['sms_subject_id'])) {
            return Subject::find($payload['sms_subject_id']);
        }

        // Fallback: match by name within school
        if (!empty($payload['subject_name'])) {
            return Subject::where('school_id', $this->inbox->school_id)
                          ->where('name', 'like', "%{$payload['subject_name']}%")
                          ->first();
        }

        return null;
    }

    /**
     * Called when all retries are exhausted — alert admin.
     */
    public function failed(\Throwable $e): void
    {
        $this->inbox->markFailed("Job failed after retries: {$e->getMessage()}");

        // TODO: notify school admin via notification
        Log::error("CBT result mapping permanently failed.", [
            'inbox_id' => $this->inbox->id,
            'error'    => $e->getMessage(),
        ]);
    }
}
