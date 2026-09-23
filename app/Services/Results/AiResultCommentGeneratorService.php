<?php

namespace App\Services\Results;

use App\Models\ResultBatch;
use App\Models\User;
use App\Services\Ai\GeminiAiService;
use Illuminate\Support\Str;
use RuntimeException;

class AiResultCommentGeneratorService
{
    public function __construct(private GeminiAiService $ai)
    {
    }

    public function generate(ResultBatch $batch, User $student, array $data): array
    {
        $instructions = $this->instructions();
        $context = $this->buildContext($batch, $student, $data);

        $result = $this->ai->generateStructuredJson($instructions, $context);
        $comments = $result['data'] ?? [];

        if (! is_array($comments) || empty($comments)) {
            throw new RuntimeException('AI returned an invalid comment structure. Try again with clearer result data.');
        }

        return [
            'comments' => [
                'general_remark' => Str::limit(trim((string) ($comments['general_remark'] ?? 'Good performance.')), 255, ''),
                'principal_comment' => Str::limit(trim((string) ($comments['principal_comment'] ?? 'Encouraged to keep up the good work.')), 255, ''),
                'class_teacher_comment' => Str::limit(trim((string) ($comments['class_teacher_comment'] ?? 'A dedicated and hardworking student.')), 255, ''),
            ],
            'usage' => $result['usage'] ?? [
                'model' => $result['model'] ?? config('gemini.model'),
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You write professional Nigerian/African school report-card comments.
Return only valid JSON. Do not include markdown.

Rules:
- Be concise, respectful, and parent-friendly.
- Do not exaggerate.
- Use the scores, attendance, behavior notes, and trend if available.
- If performance is weak, give constructive improvement guidance.
- Avoid medical, psychological, or disciplinary accusations.
- Do not mention AI.
- Keep each field under 255 characters.

Return exactly:
{
  "general_remark": "Short status such as Excellent, Very Good, Good, Fair, Needs improvement",
  "principal_comment": "Principal/HM style comment",
  "class_teacher_comment": "Class teacher style comment"
}
PROMPT;
    }

    private function buildContext(ResultBatch $batch, User $student, array $data): array
    {
        return [
            'student' => [
                'name' => trim(($student->firstname ?? '') . ' ' . ($student->surname ?? '')),
                'admission_no' => $student->reg_no,
                'class' => $student->level?->name,
                'department' => $student->department?->name,
            ],
            'period' => [
                'term' => $batch->term,
                'session' => $batch->session,
            ],
            'summary' => $data['summary'] ?? [],
            'subjects' => $data['subjects'] ?? [],
            'attendance' => $data['attendance'] ?? [],
            'behavior_notes' => $data['behavior_notes'] ?? null,
            'performance_trend' => $data['performance_trend'] ?? null,
        ];
    }
}
