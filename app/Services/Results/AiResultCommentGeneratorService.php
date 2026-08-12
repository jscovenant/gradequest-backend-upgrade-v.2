<?php

namespace App\Services\Results;

use App\Models\ResultBatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AiResultCommentGeneratorService
{
    public function generate(ResultBatch $batch, User $student, array $data): array
    {
        $apiKey = config('openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key is not configured. Add OPENAI_API_KEY to your backend .env file.');
        }

        $payload = $this->buildPayload($batch, $student, $data);

        $response = Http::withToken($apiKey)
            ->timeout((int) config('openai.timeout', 90))
            ->acceptJson()
            ->post('https://api.openai.com/v1/responses', $payload);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'OpenAI could not generate result comments at this time.');
        }

        $body = $response->json();
        $json = $this->extractOutputJson($body);
        $comments = json_decode($json, true);

        if (! is_array($comments)) {
            throw new RuntimeException('OpenAI returned an invalid comment structure. Try again with clearer result data.');
        }

        return [
            'comments' => [
                'general_remark' => Str::limit(trim((string) ($comments['general_remark'] ?? '')), 255, ''),
                'principal_comment' => Str::limit(trim((string) ($comments['principal_comment'] ?? '')), 255, ''),
                'class_teacher_comment' => Str::limit(trim((string) ($comments['class_teacher_comment'] ?? '')), 255, ''),
            ],
            'usage' => [
                'model' => $body['model'] ?? config('openai.model'),
                'input_tokens' => (int) data_get($body, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($body, 'usage.output_tokens', 0),
                'total_tokens' => (int) data_get($body, 'usage.total_tokens', 0),
            ],
        ];
    }

    private function buildPayload(ResultBatch $batch, User $student, array $data): array
    {
        $context = [
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

        $instructions = <<<'PROMPT'
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

        return [
            'model' => config('openai.model', 'gpt-5-mini'),
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $instructions]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode($context, JSON_PRETTY_PRINT)]]],
            ],
            'text' => [
                'format' => ['type' => 'json_object'],
            ],
        ];
    }

    private function extractOutputJson(array $body): string
    {
        if (is_string($body['output_text'] ?? null)) {
            return $body['output_text'];
        }

        $parts = [];
        foreach (($body['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['text'])) {
                    $parts[] = $content['text'];
                }
            }
        }

        return trim(implode("\n", $parts));
    }
}