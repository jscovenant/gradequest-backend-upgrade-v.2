<?php

namespace App\Services\Cbt;

use App\Models\CbtExam;
use App\Models\CbtExamSection;
use App\Models\CbtQuestionGroup;
use App\Imports\RawSheetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use ZipArchive;

class CbtQuestionImportService
{
    private const SUPPORTED_TYPES = [
        'single_choice',
        'multiple_choice',
        'true_false',
        'fill_blank',
        'theory',
        'comprehension',
    ];

    public function import(CbtExam $exam, UploadedFile $file, bool $preview = false): array
    {
        [$parsed, $source] = $this->parseFile($exam, $file);

        if ($parsed['errors'] !== []) {
            return $this->response($preview, $parsed, 0, $source);
        }

        if ($preview) {
            return $this->response(true, $parsed, 0, $source);
        }

        $importedCount = DB::transaction(function () use ($exam, $parsed, $source) {
            $sections = [];
            $groups = [];
            $questionCount = (int) $exam->questions()->count();
            $sectionCount = (int) $exam->sections()->count();
            $groupCount = (int) $exam->questionGroups()->count();
            $imported = 0;

            foreach ($parsed['questions'] as $item) {
                $section = null;
                if ($item['section_title'] !== '') {
                    $sectionKey = mb_strtolower($item['section_title']);
                    if (! isset($sections[$sectionKey])) {
                        $sectionCount++;
                        $sections[$sectionKey] = CbtExamSection::firstOrCreate(
                            [
                                'exam_id' => $exam->id,
                                'title' => $item['section_title'],
                            ],
                            [
                                'instructions' => null,
                                'sort_order' => $sectionCount,
                                'default_marks' => $item['marks'],
                                'shuffle_questions' => null,
                            ]
                        );
                    }
                    $section = $sections[$sectionKey];
                }

                $group = null;
                if (! empty($item['group'])) {
                    $groupKey = md5(($section?->id ?? 'none') . '|' . $item['group']['title'] . '|' . $item['group']['passage']);
                    if (! isset($groups[$groupKey])) {
                        $groupCount++;
                        $groups[$groupKey] = CbtQuestionGroup::create([
                            'exam_id' => $exam->id,
                            'section_id' => $section?->id,
                            'group_type' => 'comprehension',
                            'title' => $item['group']['title'],
                            'instructions' => $item['group']['instructions'],
                            'passage' => $item['group']['passage'],
                            'sort_order' => $groupCount,
                        ]);
                    }
                    $group = $groups[$groupKey];
                }

                $questionCount++;
                $question = $exam->questions()->create([
                    'section_id' => $section?->id,
                    'question_group_id' => $group?->id,
                    'question_type' => $item['question_type'],
                    'question_text' => $item['question_text'],
                    'instructions' => $item['instructions'] ?: null,
                    'explanation' => $item['explanation'] ?: null,
                    'marks' => $item['marks'],
                    'sort_order' => $questionCount,
                    'difficulty' => $item['difficulty'] ?: null,
                    'correct_answer' => $item['correct_answer'],
                    'metadata' => [
                        'imported_from' => $source,
                        'source_number' => $item['number'],
                    ],
                ]);

                foreach ($item['options'] as $index => $option) {
                    $question->options()->create([
                        'label' => $option['label'],
                        'option_text' => $option['option_text'],
                        'is_correct' => in_array($option['label'], $item['correct_answer'], true),
                        'sort_order' => $index + 1,
                    ]);
                }

                $imported++;
            }

            $exam->update(['total_marks' => (float) $exam->questions()->sum('marks')]);

            return $imported;
        });

        return $this->response(false, $parsed, $importedCount, $source);
    }

    private function parseFile(CbtExam $exam, UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        if ($extension === 'docx') {
            return [$this->parseLines($this->extractWordLines($file->getRealPath(), $exam)), 'word_docx'];
        }

        if (in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            return [$this->parseSpreadsheet($file), 'excel_sheet'];
        }

        throw new RuntimeException('Question import file must be .docx, .xlsx, .xls, or .csv.');
    }

    private function extractWordLines(string $path, CbtExam $exam): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required before Word import can work.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open this Word document. Please upload a valid .docx file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels') ?: '';

        if ($xml === false || trim($xml) === '') {
            $zip->close();
            throw new RuntimeException('This Word document does not contain readable question text.');
        }

        $relationships = $this->wordRelationships($relsXml);
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $lines = [];
        foreach ($xpath->query('//w:body/*') as $node) {
            if ($node->localName === 'p') {
                $line = $this->wordParagraphHtml($xpath, $node, $zip, $relationships, $exam);
            } elseif ($node->localName === 'tbl') {
                $line = $this->wordTableHtml($xpath, $node);
            } else {
                $line = '';
            }

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $zip->close();

        return $lines;
    }

    private function wordRelationships(string $relsXml): array
    {
        if (trim($relsXml) === '') {
            return [];
        }

        $dom = new \DOMDocument();
        $dom->loadXML($relsXml);
        $map = [];

        foreach ($dom->getElementsByTagName('Relationship') as $relationship) {
            $id = $relationship->getAttribute('Id');
            $target = $relationship->getAttribute('Target');
            if ($id !== '' && $target !== '') {
                $map[$id] = $target;
            }
        }

        return $map;
    }

    private function wordParagraphHtml(\DOMXPath $xpath, \DOMNode $paragraph, ZipArchive $zip, array $relationships, CbtExam $exam): string
    {
        $parts = [];
        foreach ($xpath->query('.//w:t', $paragraph) as $textNode) {
            $parts[] = $textNode->nodeValue;
        }

        foreach ($xpath->query('.//a:blip', $paragraph) as $imageNode) {
            if (! $imageNode instanceof \DOMElement) {
                continue;
            }

            $relationshipId = $imageNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
            $imageHtml = $this->wordImageHtml($zip, $relationships[$relationshipId] ?? null, $exam);
            if ($imageHtml !== '') {
                $parts[] = $imageHtml;
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    private function wordTableHtml(\DOMXPath $xpath, \DOMNode $table): string
    {
        $rows = [];
        foreach ($xpath->query('./w:tr', $table) as $row) {
            $cells = [];
            foreach ($xpath->query('./w:tc', $row) as $cell) {
                $parts = [];
                foreach ($xpath->query('.//w:t', $cell) as $textNode) {
                    $parts[] = $textNode->nodeValue;
                }
                $cells[] = '<td>' . e(trim(implode(' ', $parts))) . '</td>';
            }
            if ($cells !== []) {
                $rows[] = '<tr>' . implode('', $cells) . '</tr>';
            }
        }

        return $rows === [] ? '' : '<table><tbody>' . implode('', $rows) . '</tbody></table>';
    }

    private function wordImageHtml(ZipArchive $zip, ?string $target, CbtExam $exam): string
    {
        if (! $target) {
            return '';
        }

        $zipPath = str_starts_with($target, 'word/') ? $target : 'word/' . ltrim($target, '/');
        $zipPath = str_replace(['../', './'], '', $zipPath);
        $contents = $zip->getFromName($zipPath);
        if ($contents === false) {
            return '';
        }

        $extension = strtolower(pathinfo($zipPath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $extension = 'png';
        }

        $directory = public_path('uploads/cbt/questions');
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $name = now()->format('YmdHis') . '_' . $exam->id . '_' . Str::random(10) . '.' . $extension;
        File::put($directory . DIRECTORY_SEPARATOR . $name, $contents);

        return '<img src="' . e(url('uploads/cbt/questions/' . $name)) . '" alt="Question image" />';
    }

    private function parseSpreadsheet(UploadedFile $file): array
    {
        $import = new RawSheetImport();
        $sheets = Excel::toArray($import, $file);
        $rows = $sheets[0] ?? [];

        if (count($rows) < 2) {
            return [
                'questions' => [],
                'errors' => ['No valid question row was found in the spreadsheet.'],
            ];
        }

        $headers = array_map(fn ($header) => $this->normalizeHeader($header), $rows[0] ?? []);
        $knownHeaders = array_filter($headers);

        if (count($knownHeaders) <= 1) {
            $lines = collect($rows)
                ->flatten()
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->values()
                ->all();

            return $this->parseLines($lines);
        }

        $questions = [];
        $errors = [];

        foreach (array_slice($rows, 1) as $rowIndex => $row) {
            if (collect($row)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
                continue;
            }

            $item = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $item[$header] = $row[$index] ?? null;
                }
            }

            $lineRef = 'Row ' . ($rowIndex + 2);
            $questionText = $this->appendRichImportContent(
                trim((string) ($item['question'] ?? $item['question_text'] ?? '')),
                $item,
                ['question_table', 'question_table_html', 'table_html'],
                ['question_image', 'question_image_url', 'image_url']
            );
            if (! $this->hasVisibleContent($questionText)) {
                $errors[] = $lineRef . ' must have a question.';
                continue;
            }

            $type = strtolower(trim(str_replace([' ', '-'], '_', (string) ($item['type'] ?? $item['question_type'] ?? 'single_choice'))));
            if ($type === 'objective') {
                $type = 'single_choice';
            }
            if (! in_array($type, self::SUPPORTED_TYPES, true)) {
                $errors[] = $lineRef . ' has unsupported question type "' . ($item['type'] ?? $item['question_type'] ?? '') . '".';
                continue;
            }

            $passage = $this->appendRichImportContent(
                trim((string) ($item['passage'] ?? $item['comprehension'] ?? '')),
                $item,
                ['passage_table', 'passage_table_html'],
                ['passage_image', 'passage_image_url']
            );
            $questionType = $type === 'comprehension' ? 'single_choice' : $type;
            $options = [];

            foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $label) {
                $value = $this->appendRichImportContent(
                    trim((string) ($item['option_' . $label] ?? $item[$label] ?? '')),
                    $item,
                    ['option_' . $label . '_table', 'option_' . $label . '_table_html'],
                    ['option_' . $label . '_image', 'option_' . $label . '_image_url']
                );
                if ($this->hasVisibleContent($value)) {
                    $options[] = [
                        'label' => strtoupper($label),
                        'option_text' => $value,
                    ];
                }
            }

            if ($questionType === 'true_false' && count($options) === 0) {
                $options = [
                    ['label' => 'A', 'option_text' => 'True'],
                    ['label' => 'B', 'option_text' => 'False'],
                ];
            }

            $answerText = trim((string) ($item['answer'] ?? $item['correct_answer'] ?? ''));
            if ($questionType === 'true_false') {
                $normalized = strtolower($answerText);
                if (in_array($normalized, ['true', 't'], true)) {
                    $answerText = 'A';
                } elseif (in_array($normalized, ['false', 'f'], true)) {
                    $answerText = 'B';
                }
            }

            $correctAnswer = array_values(array_filter(array_map(
                fn ($answer) => strtoupper(trim($answer)),
                preg_split('/[,;|]/', $answerText) ?: []
            )));

            $question = [
                'number' => (int) ($item['number'] ?? $item['no'] ?? count($questions) + 1),
                'section_title' => trim((string) ($item['section'] ?? 'General Questions')) ?: 'General Questions',
                'question_type' => $questionType,
                'question_text' => $questionText,
                'instructions' => trim((string) ($item['instructions'] ?? $item['instruction'] ?? '')),
                'explanation' => trim((string) ($item['explanation'] ?? '')),
                'difficulty' => trim((string) ($item['difficulty'] ?? '')),
                'marks' => (float) ($item['marks'] ?? $item['mark'] ?? 1),
                'options' => $options,
                'correct_answer' => $correctAnswer,
                'group' => $passage !== '' ? [
                    'title' => trim((string) ($item['passage_title'] ?? $item['section'] ?? 'Comprehension')),
                    'instructions' => trim((string) ($item['passage_instruction'] ?? 'Read the passage carefully and answer the questions that follow.')),
                    'passage' => $passage,
                ] : null,
            ];

            $this->validateParsedQuestion($question, $lineRef, $errors);
            $questions[] = $question;
        }

        if (count($questions) === 0 && $errors === []) {
            $errors[] = 'No valid question row was found in the spreadsheet.';
        }

        return [
            'questions' => $questions,
            'errors' => $errors,
        ];
    }

    private function parseLines(array $lines): array
    {
        $sectionTitle = 'General Questions';
        $type = 'single_choice';
        $marks = 1.0;
        $difficulty = null;
        $instructions = null;
        $explanation = null;
        $activeGroup = null;
        $collectingPassage = false;
        $passageLines = [];
        $questions = [];
        $errors = [];
        $current = null;

        $finishPassage = function () use (&$activeGroup, &$passageLines, &$collectingPassage, &$sectionTitle): void {
            $passage = trim(implode("\n", $passageLines));
            if ($passage !== '') {
                $activeGroup = [
                    'title' => $sectionTitle,
                    'instructions' => 'Read the passage carefully and answer the questions that follow.',
                    'passage' => $passage,
                ];
            }
            $passageLines = [];
            $collectingPassage = false;
        };

        $finishQuestion = function () use (&$current, &$questions, &$errors): void {
            if (! $current) {
                return;
            }

            $lineRef = 'Question ' . ($current['number'] ?: count($questions) + 1);
            $current['question_text'] = trim($current['question_text']);

            if ($current['question_text'] === '') {
                $errors[] = $lineRef . ' has no question text.';
            }

            if ($current['question_type'] === 'true_false' && count($current['options']) === 0) {
                $current['options'] = [
                    ['label' => 'A', 'option_text' => 'True'],
                    ['label' => 'B', 'option_text' => 'False'],
                ];
            }

            $this->validateParsedQuestion($current, $lineRef, $errors);

            $questions[] = $current;
            $current = null;
        };

        foreach ($lines as $line) {
            if ($collectingPassage) {
                if (preg_match('/^END\s+PASSAGE$/i', $line)) {
                    $finishPassage();
                    continue;
                }

                if (! preg_match('/^\d+[\.\)]\s+/', $line)) {
                    $passageLines[] = $line;
                    continue;
                }

                $finishPassage();
            }

            if (preg_match('/^SECTION:\s*(.+)$/i', $line, $match)) {
                $finishQuestion();
                $sectionTitle = trim($match[1]) ?: 'General Questions';
                $activeGroup = null;
                continue;
            }

            if (preg_match('/^TYPE:\s*(.+)$/i', $line, $match)) {
                $finishQuestion();
                $candidate = strtolower(trim(str_replace([' ', '-'], '_', $match[1])));
                if (! in_array($candidate, self::SUPPORTED_TYPES, true)) {
                    $errors[] = 'Unsupported question type "' . trim($match[1]) . '".';
                    continue;
                }
                $type = $candidate;
                if ($type !== 'comprehension') {
                    $activeGroup = null;
                }
                continue;
            }

            if (preg_match('/^MARKS?:\s*([0-9]+(?:\.[0-9]+)?)$/i', $line, $match)) {
                $marks = (float) $match[1];
                continue;
            }

            if (preg_match('/^DIFFICULTY:\s*(.+)$/i', $line, $match)) {
                $difficulty = trim($match[1]);
                continue;
            }

            if (preg_match('/^INSTRUCTION:\s*(.+)$/i', $line, $match)) {
                $instructions = trim($match[1]);
                continue;
            }

            if (preg_match('/^EXPLANATION:\s*(.+)$/i', $line, $match)) {
                $explanation = trim($match[1]);
                continue;
            }

            if (preg_match('/^PASSAGE:\s*(.*)$/i', $line, $match)) {
                $finishQuestion();
                $type = 'comprehension';
                $collectingPassage = true;
                $passageLines = [];
                if (trim($match[1]) !== '') {
                    $passageLines[] = trim($match[1]);
                }
                continue;
            }

            if (preg_match('/^(\d+)[\.\)]\s+(.+)$/', $line, $match)) {
                $finishQuestion();
                $currentType = $type === 'comprehension' ? 'single_choice' : $type;
                $current = [
                    'number' => (int) $match[1],
                    'section_title' => $sectionTitle,
                    'question_type' => $currentType,
                    'question_text' => trim($match[2]),
                    'instructions' => $instructions,
                    'explanation' => $explanation,
                    'difficulty' => $difficulty,
                    'marks' => $marks,
                    'options' => [],
                    'correct_answer' => [],
                    'group' => $activeGroup,
                ];
                $instructions = null;
                $explanation = null;
                continue;
            }

            if (preg_match('/^([A-F])[\.\)]\s+(.+)$/i', $line, $match)) {
                if (! $current) {
                    $errors[] = 'Option "' . $line . '" appears before a question.';
                    continue;
                }
                $current['options'][] = [
                    'label' => strtoupper($match[1]),
                    'option_text' => trim($match[2]),
                ];
                continue;
            }

            if (preg_match('/^ANSWER:\s*(.+)$/i', $line, $match)) {
                if (! $current) {
                    $errors[] = 'ANSWER appears before a question.';
                    continue;
                }
                $answerText = trim($match[1]);
                if ($current['question_type'] === 'true_false') {
                    $normalized = strtolower($answerText);
                    if (in_array($normalized, ['true', 't'], true)) {
                        $answerText = 'A';
                    } elseif (in_array($normalized, ['false', 'f'], true)) {
                        $answerText = 'B';
                    }
                }
                $current['correct_answer'] = array_values(array_filter(array_map(
                    fn ($answer) => strtoupper(trim($answer)),
                    preg_split('/[,;|]/', $answerText) ?: []
                )));
                continue;
            }

            if ($current) {
                $current['question_text'] .= "\n" . $line;
            }
        }

        if ($collectingPassage) {
            $finishPassage();
        }
        $finishQuestion();

        if (count($questions) === 0) {
            $errors[] = 'No valid question was found in the uploaded file.';
        }

        return [
            'questions' => $questions,
            'errors' => $errors,
        ];
    }

    private function validateParsedQuestion(array $question, string $lineRef, array &$errors): void
    {
        if (in_array($question['question_type'], ['single_choice', 'multiple_choice', 'true_false'], true)) {
            if (count($question['options']) < 2) {
                $errors[] = $lineRef . ' must have at least two options.';
            }

            if (count($question['correct_answer']) === 0) {
                $errors[] = $lineRef . ' must have an ANSWER line or answer column.';
            }

            $labels = array_map(fn ($option) => $option['label'], $question['options']);
            foreach ($question['correct_answer'] as $answer) {
                if (! in_array($answer, $labels, true)) {
                    $errors[] = $lineRef . ' answer "' . $answer . '" does not match any option label.';
                }
            }

            if ($question['question_type'] !== 'multiple_choice' && count($question['correct_answer']) > 1) {
                $errors[] = $lineRef . ' can only have one correct answer.';
            }
        }

        if ($question['marks'] <= 0) {
            $errors[] = $lineRef . ' must have marks greater than zero.';
        }
    }

    private function appendRichImportContent(string $content, array $item, array $tableKeys, array $imageKeys): string
    {
        $parts = [$content];

        foreach ($tableKeys as $key) {
            $table = trim((string) ($item[$key] ?? ''));
            if ($table !== '') {
                $parts[] = $this->normalizeImportedTable($table);
            }
        }

        foreach ($imageKeys as $key) {
            $url = trim((string) ($item[$key] ?? ''));
            if ($url !== '') {
                $parts[] = $this->imageTagFromUrl($url);
            }
        }

        return trim(implode("\n", array_filter($parts, fn ($value) => trim((string) $value) !== '')));
    }

    private function normalizeImportedTable(string $value): string
    {
        if (str_contains(strtolower($value), '<table')) {
            return $value;
        }

        $rows = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $htmlRows = [];

        foreach ($rows as $row) {
            $cells = array_map('trim', preg_split('/\||,/', $row) ?: []);
            $cells = array_filter($cells, fn ($cell) => $cell !== '');
            if ($cells === []) {
                continue;
            }
            $htmlRows[] = '<tr>' . implode('', array_map(fn ($cell) => '<td>' . e($cell) . '</td>', $cells)) . '</tr>';
        }

        return $htmlRows === [] ? '' : '<table><tbody>' . implode('', $htmlRows) . '</tbody></table>';
    }

    private function imageTagFromUrl(string $url): string
    {
        if (! preg_match('/^(https?:\/\/|\/uploads\/)/i', $url)) {
            return '';
        }

        return '<img src="' . e($url) . '" alt="Question image" />';
    }

    private function hasVisibleContent(string $value): bool
    {
        $text = trim(strip_tags($value));
        return $text !== '' || str_contains(strtolower($value), '<img') || str_contains(strtolower($value), '<table');
    }

    private function normalizeHeader(mixed $value): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $value), '_'));
    }

    private function response(bool $preview, array $parsed, int $importedCount, string $source = 'word_docx'): array
    {
        $questions = $parsed['questions'];
        $sections = collect($questions)->pluck('section_title')->filter()->unique()->count();
        $passages = collect($questions)->filter(fn ($question) => ! empty($question['group']))->pluck('group.passage')->unique()->count();

        return [
            'message' => $preview ? 'Question import preview generated.' : 'Questions imported.',
            'preview' => $preview,
            'source' => $source,
            'summary' => [
                'questions_detected' => count($questions),
                'sections_detected' => $sections,
                'passages_detected' => $passages,
                'errors_count' => count($parsed['errors']),
                'imported_count' => $importedCount,
            ],
            'errors' => $parsed['errors'],
            'questions' => array_slice(array_map(fn ($question) => [
                'number' => $question['number'],
                'section' => $question['section_title'],
                'type' => $question['question_type'],
                'text' => mb_substr($question['question_text'], 0, 120),
                'options' => count($question['options']),
                'marks' => $question['marks'],
                'has_passage' => ! empty($question['group']),
            ], $questions), 0, 30),
        ];
    }
}
