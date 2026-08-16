<?php

namespace App\Services\Lessons;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AiLessonPlanGeneratorService
{
    public function generate(array $data): array
    {
        return $this->requestStructuredContent($data, $this->lessonPlanInstructions(), fn (array $payload) => [
            'lesson_plan' => $this->normalizePlan($payload, $data),
        ]);
    }

    public function generateScheme(array $data): array
    {
        return $this->requestStructuredContent($data, $this->schemeInstructions(), fn (array $payload) => [
            'scheme' => $this->normalizeScheme($payload, $data),
        ]);
    }

    public function generateLessonNote(array $data): array
    {
        return $this->requestStructuredContent($data, $this->lessonNoteInstructions(), fn (array $payload) => [
            'lesson_note' => $this->normalizeLessonNote($payload, $data),
        ]);
    }

    private function requestStructuredContent(array $data, string $instructions, callable $normalizer): array
    {
        $apiKey = config('openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key is not configured. Add OPENAI_API_KEY to your backend .env file.');
        }

        $timeout = max(30, (int) config('openai.timeout', 90));
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 20);
        }

        $response = Http::withToken($apiKey)
            ->connectTimeout(15)
            ->timeout($timeout)
            ->acceptJson()
            ->post('https://api.openai.com/v1/responses', [
                'model' => config('openai.model', 'gpt-5-mini'),
                'input' => [
                    ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $instructions]]],
                    ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode($this->compactInput($data), JSON_UNESCAPED_SLASHES)]]],
                ],
                'text' => ['format' => ['type' => 'json_object']],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'OpenAI could not process this request at this time.');
        }

        $body = $response->json();
        $payload = json_decode($this->extractOutputJson($body), true);

        if (! is_array($payload)) {
            throw new RuntimeException('OpenAI returned an invalid structure. Try again with clearer details.');
        }

        return array_merge($normalizer($payload), [
            'usage' => [
                'model' => $body['model'] ?? config('openai.model'),
                'input_tokens' => (int) data_get($body, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($body, 'usage.output_tokens', 0),
                'total_tokens' => (int) data_get($body, 'usage.total_tokens', 0),
            ],
        ]);
    }

    private function lessonPlanInstructions(): string
    {
        return <<<'PROMPT'
You generate practical lesson plans for Nigerian/African schools.
Return only valid JSON. Do not include markdown.
Rules:
- Use simple teacher-friendly language.
- Make the lesson realistic for the selected class, subject, topic, and duration.
- Include measurable objectives, activities, assessment, homework, teaching aids, and board summary.
- Avoid unsafe, controversial, or unverifiable claims.
- Do not mention AI.
Return exactly this JSON shape:
{"title":"Lesson title","subject":"Subject","class":"Class","topic":"Topic","duration_minutes":40,"objectives":["By the end of the lesson, learners should be able to..."],"teaching_aids":["Whiteboard"],"previous_knowledge":"Short text","introduction":"Short teacher opening","teacher_activities":["Step-by-step teacher action"],"learner_activities":["Step-by-step learner action"],"assessment":["Question or task"],"homework":["Homework task"],"board_summary":["Key point"],"closure":"Short closing statement"}
PROMPT;
    }

    private function schemeInstructions(): string
    {
        return <<<'PROMPT'
You generate a practical scheme of work for Nigerian/African schools.
Return only valid JSON. Do not include markdown.
Rules:
- Follow the selected government curriculum where provided.
- Break the term into weekly teachable topics. Do not exceed the requested number of weeks.
- Keep each week concise: one topic, 2 subtopics, 2 objectives, 2 activities, 2 resources, and 2 assessment questions.
- Keep topics suitable for the selected class and subject.
- Do not mention AI.
Return exactly this JSON shape:
{"title":"Scheme title","subject":"Subject","class":"Class","term":"First Term","curriculum":"Curriculum name","topics":[{"week":1,"topic":"Topic","subtopics":["Subtopic"],"objectives":["Objective"],"activities":["Activity"],"resources":["Resource"],"assessment":["Assessment question"]}],"plain_text":"Readable full scheme of work text"}
PROMPT;
    }

    private function lessonNoteInstructions(): string
    {
        return <<<'PROMPT'
You generate complete classroom lesson notes for Nigerian/African schools.
Return only valid JSON. Do not include markdown.
Rules:
- The note must be teachable, clear, and age-appropriate.
- Include detailed explanation, examples, class activity, board notes, summary, homework, and quiz questions.
- If a lesson plan is supplied, follow it.
- If scheme context is supplied, align the note with it.
- Suggest optional YouTube search phrases, not fabricated video URLs.
- Do not mention AI.
Return exactly this JSON shape:
{"title":"Lesson note title","subject":"Subject","class":"Class","topic":"Topic","sections":[{"heading":"Heading","body":"Detailed explanation"}],"examples":["Example"],"board_notes":["Board note"],"class_activity":["Activity"],"summary":["Summary point"],"homework":["Homework"],"quiz":[{"question":"Question","answer":"Answer"}],"youtube_search_terms":["Search phrase"]}
PROMPT;
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

    private function normalizeScheme(array $scheme, array $data): array
    {
        $topics = collect($scheme['topics'] ?? [])->map(function ($topic, $index) {
            return [
                'week' => (int) ($topic['week'] ?? ($index + 1)),
                'topic' => trim((string) ($topic['topic'] ?? '')),
                'subtopics' => $this->stringList($topic['subtopics'] ?? []),
                'objectives' => $this->stringList($topic['objectives'] ?? []),
                'activities' => $this->stringList($topic['activities'] ?? []),
                'resources' => $this->stringList($topic['resources'] ?? []),
                'assessment' => $this->stringList($topic['assessment'] ?? []),
            ];
        })->filter(fn ($topic) => $topic['topic'] !== '')->values()->all();

        return [
            'title' => Str::limit(trim((string) ($scheme['title'] ?? (($data['subject'] ?? 'Subject') . ' Scheme of Work'))), 180, ''),
            'subject' => trim((string) ($scheme['subject'] ?? $data['subject'] ?? '')),
            'class' => trim((string) ($scheme['class'] ?? $data['class'] ?? '')),
            'term' => trim((string) ($scheme['term'] ?? $data['term'] ?? '')),
            'curriculum' => trim((string) ($scheme['curriculum'] ?? $data['curriculum'] ?? '')),
            'topics' => $topics,
            'plain_text' => trim((string) ($scheme['plain_text'] ?? '')),
        ];
    }

    private function normalizeLessonNote(array $note, array $data): array
    {
        return [
            'title' => Str::limit(trim((string) ($note['title'] ?? (($data['topic'] ?? 'Lesson') . ' Lesson Note'))), 180, ''),
            'subject' => trim((string) ($note['subject'] ?? $data['subject'] ?? '')),
            'class' => trim((string) ($note['class'] ?? $data['class'] ?? '')),
            'topic' => trim((string) ($note['topic'] ?? $data['topic'] ?? '')),
            'sections' => collect($note['sections'] ?? [])->map(fn ($section) => [
                'heading' => trim((string) ($section['heading'] ?? 'Note')),
                'body' => trim((string) ($section['body'] ?? '')),
            ])->filter(fn ($section) => $section['body'] !== '')->values()->all(),
            'examples' => $this->stringList($note['examples'] ?? []),
            'board_notes' => $this->stringList($note['board_notes'] ?? []),
            'class_activity' => $this->stringList($note['class_activity'] ?? []),
            'summary' => $this->stringList($note['summary'] ?? []),
            'homework' => $this->stringList($note['homework'] ?? []),
            'quiz' => collect($note['quiz'] ?? [])->map(fn ($item) => [
                'question' => trim((string) ($item['question'] ?? '')),
                'answer' => trim((string) ($item['answer'] ?? '')),
            ])->filter(fn ($item) => $item['question'] !== '')->values()->all(),
            'youtube_search_terms' => $this->stringList($note['youtube_search_terms'] ?? []),
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

    private function compactInput(array $data): array
    {
        return collect($data)
            ->map(function ($value) {
                if (is_string($value)) {
                    return Str::limit($value, 1800, '');
                }

                return $value;
            })
            ->all();
    }
}
