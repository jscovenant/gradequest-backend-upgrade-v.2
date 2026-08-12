<?php

namespace App\Services\Lessons;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AiLessonPlanGeneratorService
{
    public function generate(array $data): array
    {
        $apiKey = config('openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key is not configured. Add OPENAI_API_KEY to your backend .env file.');
        }

        $response = Http::withToken($apiKey)
            ->timeout((int) config('openai.timeout', 90))
            ->acceptJson()
            ->post('https://api.openai.com/v1/responses', $this->buildPayload($data));

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'OpenAI could not generate this lesson plan at this time.');
        }

        $body = $response->json();
        $plan = json_decode($this->extractOutputJson($body), true);

        if (! is_array($plan)) {
            throw new RuntimeException('OpenAI returned an invalid lesson plan structure. Try again with a clearer topic.');
        }

        return [
            'lesson_plan' => $this->normalizePlan($plan, $data),
            'usage' => [
                'model' => $body['model'] ?? config('openai.model'),
                'input_tokens' => (int) data_get($body, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($body, 'usage.output_tokens', 0),
                'total_tokens' => (int) data_get($body, 'usage.total_tokens', 0),
            ],
        ];
    }

    private function buildPayload(array $data): array
    {
        $context = [
            'subject' => $data['subject'],
            'class' => $data['class'],
            'topic' => $data['topic'],
            'duration_minutes' => (int) $data['duration_minutes'],
            'teacher_notes' => $data['teacher_notes'] ?? null,
            'school_context' => 'Nigerian/African primary and secondary school classroom',
        ];

        $instructions = <<<'PROMPT'
You generate practical lesson plans for Nigerian/African schools.
Return only valid JSON. Do not include markdown.

Rules:
- Use simple teacher-friendly language.
- Make the lesson realistic for the selected class, subject, topic, and duration.
- Include measurable objectives.
- Include introduction, teacher activities, learner activities, assessment, homework, teaching aids, and board summary.
- Avoid unsafe, controversial, or unverifiable claims.
- Do not mention AI.

Return exactly this JSON shape:
{
  "title": "Lesson title",
  "subject": "Subject",
  "class": "Class",
  "topic": "Topic",
  "duration_minutes": 40,
  "objectives": ["By the end of the lesson, learners should be able to..."],
  "teaching_aids": ["Whiteboard"],
  "previous_knowledge": "Short text",
  "introduction": "Short teacher opening",
  "teacher_activities": ["Step-by-step teacher action"],
  "learner_activities": ["Step-by-step learner action"],
  "assessment": ["Question or task"],
  "homework": ["Homework task"],
  "board_summary": ["Key point"],
  "closure": "Short closing statement"
}
PROMPT;

        return [
            'model' => config('openai.model', 'gpt-5-mini'),
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $instructions]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode($context, JSON_PRETTY_PRINT)]]],
            ],
            'text' => ['format' => ['type' => 'json_object']],
        ];
    }

    private function normalizePlan(array $plan, array $data): array
    {
        return [
            'title' => Str::limit(trim((string) ($plan['title'] ?? $data['topic'])), 160, ''),
            'subject' => trim((string) ($plan['subject'] ?? $data['subject'])),
            'class' => trim((string) ($plan['class'] ?? $data['class'])),
            'topic' => trim((string) ($plan['topic'] ?? $data['topic'])),
            'duration_minutes' => (int) ($plan['duration_minutes'] ?? $data['duration_minutes']),
            'objectives' => $this->stringList($plan['objectives'] ?? []),
            'teaching_aids' => $this->stringList($plan['teaching_aids'] ?? []),
            'previous_knowledge' => trim((string) ($plan['previous_knowledge'] ?? '')),
            'introduction' => trim((string) ($plan['introduction'] ?? '')),
            'teacher_activities' => $this->stringList($plan['teacher_activities'] ?? []),
            'learner_activities' => $this->stringList($plan['learner_activities'] ?? []),
            'assessment' => $this->stringList($plan['assessment'] ?? []),
            'homework' => $this->stringList($plan['homework'] ?? []),
            'board_summary' => $this->stringList($plan['board_summary'] ?? []),
            'closure' => trim((string) ($plan['closure'] ?? '')),
        ];
    }

    private function stringList($items): array
    {
        return collect(is_array($items) ? $items : [$items])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
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
