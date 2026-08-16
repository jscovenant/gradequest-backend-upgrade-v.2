<?php

namespace App\Services\Cbt;

use App\Models\CbtExam;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class AiCbtQuestionGeneratorService
{
    private const MAX_SOURCE_CHARS = 30000;

    public function generate(CbtExam $exam, array $data): array
    {
        $apiKey = config('openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key is not configured. Add OPENAI_API_KEY to your backend .env file.');
        }

        $sourceText = $this->sourceText($data['source_file'] ?? null, (string) ($data['source_text'] ?? ''));

        if ($sourceText === '' && trim((string) ($data['topics'] ?? '')) === '') {
            throw new RuntimeException('Upload a teacher note/manual or enter topics separated by commas.');
        }

        $payload = $this->buildPayload($exam, $data, $sourceText);

        $timeout = max(30, (int) config('openai.timeout', 90));
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 20);
        }

        $response = Http::withToken($apiKey)
            ->connectTimeout(15)
            ->timeout($timeout)
            ->acceptJson()
            ->post('https://api.openai.com/v1/responses', $payload);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'OpenAI could not generate questions at this time.');
        }

        $body = $response->json();
        $json = $this->extractOutputJson($body);
        $draft = json_decode($json, true);

        if (! is_array($draft)) {
            throw new RuntimeException('OpenAI returned an invalid question structure. Try again with fewer questions or clearer topics.');
        }

        return [
            'draft' => $this->normalizeDraft($draft, $data),
            'usage' => [
                'model' => $body['model'] ?? config('openai.model'),
                'input_tokens' => (int) data_get($body, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($body, 'usage.output_tokens', 0),
                'total_tokens' => (int) data_get($body, 'usage.total_tokens', 0),
            ],
        ];
    }

    private function buildPayload(CbtExam $exam, array $data, string $sourceText): array
    {
        $questionCount = max(1, min(80, (int) ($data['question_count'] ?? 20)));
        $topics = trim((string) ($data['topics'] ?? ''));
        $formats = $data['formats'] ?? ['single_choice'];
        $difficulty = (string) ($data['difficulty'] ?? 'normal');

        $instructions = <<<PROMPT
You generate Nigerian/African school CBT exam draft questions.
Return only valid JSON. Do not include markdown.

Rules:
- Generate exactly {$questionCount} questions across the requested formats.
- Use the exam class, subject, topics, and uploaded teacher note as the source of truth.
- If comprehension is requested, create a passage group and attach its questions to that group.
- If instruction-only sections are useful, create sections with clear titles such as "Objective Questions", "Comprehension", "Fill in the Gap", "Theory".
- Use simple, age-appropriate English.
- Do not invent controversial facts.
- Every objective question must have options and correct answers.
- For single_choice and true_false, correct_answer must contain one label.
- For multiple_choice, correct_answer may contain multiple labels.
- For fill_blank, correct_answer should contain accepted text answers.
- For theory, correct_answer can be empty, but explanation/marking guide should be provided.
- Keep passage blocks together by using groups.

Supported question_type values:
single_choice, multiple_choice, true_false, fill_blank, theory.

Return this exact JSON shape:
{
  "sections": [
    {
      "title": "Objective Questions",
      "instructions": "Answer all questions.",
      "questions": [
        {
          "question_type": "single_choice",
          "question_text": "Question text",
          "instructions": "",
          "marks": 1,
          "difficulty": "{$difficulty}",
          "options": [{"label":"A","option_text":"..."}],
          "correct_answer": ["A"],
          "explanation": "Short explanation"
        }
      ],
      "groups": [
        {
          "group_type": "comprehension",
          "title": "Comprehension Passage",
          "instructions": "Read the passage carefully and answer the questions.",
          "passage": "Passage text",
          "questions": []
        }
      ]
    }
  ]
}
PROMPT;

        $context = [
            'exam_title' => $exam->title,
            'subject' => $exam->subject?->name,
            'class' => $exam->class?->name,
            'school_section' => $exam->section?->name,
            'department' => $exam->department?->name,
            'topics' => $topics,
            'requested_formats' => $formats,
            'difficulty' => $difficulty,
            'question_count' => $questionCount,
            'teacher_note_excerpt' => Str::limit($sourceText, self::MAX_SOURCE_CHARS, ''),
        ];

        return [
            'model' => config('openai.model', 'gpt-5-mini'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $instructions],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => json_encode($context, JSON_PRETTY_PRINT)],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_object',
                ],
            ],
        ];
    }

    private function sourceText(?UploadedFile $file, string $sourceText): string
    {
        $text = trim($sourceText);

        if (! $file) {
            return Str::limit($text, self::MAX_SOURCE_CHARS, '');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        if ($extension === 'docx') {
            $text .= "\n" . $this->extractDocxText($file->getRealPath());
        } elseif ($extension === 'txt') {
            $text .= "\n" . (string) file_get_contents($file->getRealPath());
        } else {
            throw new RuntimeException('AI source file must be a .docx or .txt teacher note.');
        }

        return trim(Str::limit($text, self::MAX_SOURCE_CHARS, ''));
    }

    private function extractDocxText(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required before Word document AI generation can work.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open this Word document. Please upload a valid .docx file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false || trim($xml) === '') {
            throw new RuntimeException('This Word document does not contain readable text.');
        }

        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
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

    private function normalizeDraft(array $draft, array $data): array
    {
        $sections = collect($draft['sections'] ?? [])
            ->filter(fn ($section) => is_array($section))
            ->map(function (array $section) use ($data) {
                return [
                    'title' => trim((string) ($section['title'] ?? 'AI Generated Questions')) ?: 'AI Generated Questions',
                    'instructions' => trim((string) ($section['instructions'] ?? '')),
                    'questions' => $this->normalizeQuestions($section['questions'] ?? [], $data),
                    'groups' => collect($section['groups'] ?? [])
                        ->filter(fn ($group) => is_array($group))
                        ->map(fn (array $group) => [
                            'group_type' => in_array(($group['group_type'] ?? 'comprehension'), ['instruction', 'comprehension', 'case_study'], true)
                                ? $group['group_type']
                                : 'comprehension',
                            'title' => trim((string) ($group['title'] ?? 'Comprehension Passage')),
                            'instructions' => trim((string) ($group['instructions'] ?? 'Read the passage carefully and answer the questions.')),
                            'passage' => trim((string) ($group['passage'] ?? '')),
                            'questions' => $this->normalizeQuestions($group['questions'] ?? [], $data),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'sections' => $sections,
            'summary' => [
                'sections_detected' => count($sections),
                'groups_detected' => collect($sections)->sum(fn ($section) => count($section['groups'] ?? [])),
                'questions_detected' => collect($sections)->sum(function ($section) {
                    return count($section['questions'] ?? []) + collect($section['groups'] ?? [])->sum(fn ($group) => count($group['questions'] ?? []));
                }),
            ],
        ];
    }

    private function normalizeQuestions(array $questions, array $data): array
    {
        return collect($questions)
            ->filter(fn ($question) => is_array($question) && trim((string) ($question['question_text'] ?? '')) !== '')
            ->map(function (array $question) use ($data) {
                $type = $question['question_type'] ?? 'single_choice';
                $type = in_array($type, ['single_choice', 'multiple_choice', 'true_false', 'fill_blank', 'theory'], true) ? $type : 'single_choice';
                $options = collect($question['options'] ?? [])
                    ->filter(fn ($option) => is_array($option) && trim((string) ($option['option_text'] ?? '')) !== '')
                    ->values()
                    ->map(fn ($option, $index) => [
                        'label' => strtoupper((string) ($option['label'] ?? chr(65 + $index))),
                        'option_text' => trim((string) $option['option_text']),
                    ])
                    ->all();

                return [
                    'question_type' => $type,
                    'question_text' => trim((string) $question['question_text']),
                    'instructions' => trim((string) ($question['instructions'] ?? '')),
                    'marks' => max(0.5, (float) ($question['marks'] ?? ($data['marks_per_question'] ?? 1))),
                    'difficulty' => trim((string) ($question['difficulty'] ?? ($data['difficulty'] ?? 'normal'))),
                    'options' => $options,
                    'correct_answer' => array_values(array_filter(array_map(
                        fn ($answer) => is_string($answer) ? trim($answer) : (string) $answer,
                        is_array($question['correct_answer'] ?? null) ? $question['correct_answer'] : []
                    ))),
                    'explanation' => trim((string) ($question['explanation'] ?? '')),
                ];
            })
            ->values()
            ->all();
    }
}


