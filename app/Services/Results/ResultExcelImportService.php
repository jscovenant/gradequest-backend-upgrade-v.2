<?php

namespace App\Services\Results;

use App\Exports\ResultTemplateExport;
use App\Imports\RawSheetImport;
use App\Jobs\ScanBatchResultAnomaliesJob;
use App\Jobs\UpdateResultSubmissionMonitorJob;
use App\Models\ResultBatch;
use App\Models\ResultTemplateSetting;
use App\Services\SchoolBillingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ResultExcelImportService
{
    public function __construct(
        private ResultComputeService $computeService,
        private SchoolBillingService $schoolBillingService
    ) {}

    public function template(ResultBatch $batch, string $format = 'xls', string $assessmentFormat = 'ca_exam', ?int $departmentId = null)
    {
        $students = $this->studentsForBatch($batch, $departmentId);
        $subjects = $this->subjectsForBatch($batch, $students, $departmentId);
        $components = $this->assessmentComponents($assessmentFormat);

        $headers = ['Admission No', 'Student Name'];
        foreach ($subjects as $subject) {
            foreach ($components as $component) {
                $headers[] = $subject->name . ' ' . $component['label'];
            }
            $headers[] = $subject->name . ' Exam';
        }

        $rows = [];
        foreach ($students as $student) {
            $row = [$student->reg_no, trim(($student->surname ?? '') . ' ' . ($student->firstname ?? ''))];
            foreach ($subjects as $subject) {
                foreach ($components as $component) {
                    $row[] = '';
                }
                $row[] = '';
            }
            $rows[] = $row;
        }

        $format = strtolower($format);
        $extension = $format === 'csv' ? 'csv' : ($format === 'xls' ? 'xls' : 'xlsx');
        $writerType = match ($extension) {
            'csv' => ExcelFormat::CSV,
            'xls' => ExcelFormat::XLS,
            default => ExcelFormat::XLSX,
        };

        $fileName = 'result_upload_template_batch_' . $batch->id . '.' . $extension;

        return Excel::download(new ResultTemplateExport($headers, $rows), $fileName, $writerType);
    }

    public function preview(ResultBatch $batch, UploadedFile $file, ?int $departmentId = null): array
    {
        [$headers, $rawRows] = $this->readRows($file);
        $students = $this->studentsForBatch($batch, $departmentId)->keyBy(fn ($student) => $this->normalizeKey($student->reg_no));
        $subjects = $this->subjectsForBatch($batch, $students->values(), $departmentId);
        $subjectColumns = $this->mapSubjectColumns($headers, $subjects);

        $errors = [];
        $warnings = [];
        $rows = [];
        $seenAdmissionNos = [];
        $columnPolicy = $this->resultColumnPolicy($batch);
        $previousTermsForPolicy = $this->previousTermsForPolicy($batch, $columnPolicy);
        $missingPreviousScoreCount = 0;
        $headerViolations = $this->disallowedCarryOverHeaders($headers, $columnPolicy);
        foreach ($headerViolations as $message) {
            $errors[] = $message;
        }

        if (empty($subjectColumns)) {
            $errors[] = 'No subject score columns were found. Download a fresh template and try again.';
        }

        foreach ($rawRows as $index => $rawRow) {
            $rowNumber = $index + 2;
            if ($this->isEmptyRow($rawRow)) {
                continue;
            }

            $admissionNo = trim((string)($rawRow['admission_no'] ?? $rawRow['reg_no'] ?? $rawRow['admission_number'] ?? ''));
            if ($admissionNo === '') {
                $errors[] = "Row {$rowNumber}: Admission No is required.";
                continue;
            }

            $admissionKey = $this->normalizeKey($admissionNo);
            if (isset($seenAdmissionNos[$admissionKey])) {
                $errors[] = "Row {$rowNumber}: Duplicate admission number {$admissionNo}.";
                continue;
            }
            $seenAdmissionNos[$admissionKey] = true;

            $student = $students->get($admissionKey);
            if (! $student) {
                $errors[] = $departmentId
                    ? "Row {$rowNumber}: Student with admission number {$admissionNo} was not found in this class and department."
                    : "Row {$rowNumber}: Student with admission number {$admissionNo} was not found in this class.";
                continue;
            }

            $billingStatus = $this->schoolBillingService->resultEntryStatus(
                (int) $batch->school_id,
                (int) $student->id,
                (string) $batch->session,
                (string) $batch->term
            );

            if (! ($billingStatus['allowed'] ?? false)) {
                $errors[] = "Row {$rowNumber}: {$admissionNo} cannot be imported. {$billingStatus['message']}";
                continue;
            }

            $subjectScores = [];
            foreach ($subjectColumns as $subjectId => $columns) {
                $caComponents = [];
                $caTotal = 0.0;
                $hasCaScore = false;
                foreach ($columns['ca'] as $component) {
                    $score = $this->nullableNumber($rawRow[$component['key']] ?? null);
                    if ($score !== null) {
                        $hasCaScore = true;
                        $caTotal += $score;
                    }
                    $caComponents[$component['component_key']] = $score;
                }

                $exam = $this->nullableNumber($rawRow[$columns['exam']] ?? null);

                if (! $hasCaScore && $exam === null) {
                    continue;
                }

                $subjectName = $columns['name'];
                if (! $hasCaScore || $exam === null) {
                    $errors[] = "Row {$rowNumber}: {$subjectName} must have all selected CA scores and Exam score.";
                    continue;
                }

                if (collect($caComponents)->contains(fn ($score) => $score === null)) {
                    $errors[] = "Row {$rowNumber}: {$subjectName} has an empty CA column. Fill all CA columns or leave the whole subject blank.";
                    continue;
                }

                if (collect($caComponents)->contains(fn ($score) => $score < 0) || $exam < 0) {
                    $errors[] = "Row {$rowNumber}: {$subjectName} scores cannot be negative.";
                    continue;
                }

                if (collect($caComponents)->contains(fn ($score) => $score > 100) || $exam > 100) {
                    $errors[] = "Row {$rowNumber}: {$subjectName} CA/Exam cannot be greater than 100.";
                    continue;
                }

                if (($caTotal + $exam) > 100) {
                    $errors[] = "Row {$rowNumber}: {$subjectName} total CA + Exam cannot be greater than 100.";
                    continue;
                }

                $subjectScores[] = [
                    'subject_id' => (int) $subjectId,
                    'subject_name' => $subjectName,
                    'ca' => $caComponents,
                    'ca_total' => round($caTotal, 2),
                    'exam' => $exam,
                    'total' => round($caTotal + $exam, 2),
                ];

                if (! empty($previousTermsForPolicy)) {
                    $previousScores = $this->previousSubjectScores(
                        $batch,
                        (int) $student->id,
                        (int) $subjectId,
                        $previousTermsForPolicy
                    );
                    $missingPreviousScoreCount += count(array_diff($previousTermsForPolicy, array_keys($previousScores)));
                }
            }

            if (empty($subjectScores)) {
                $warnings[] = "Row {$rowNumber}: {$admissionNo} has no scores entered.";
            }

            $rows[] = [
                'row' => $rowNumber,
                'student_id' => (int) $student->id,
                'admission_no' => $student->reg_no,
                'student_name' => trim(($student->surname ?? '') . ' ' . ($student->firstname ?? '')),
                'subjects' => $subjectScores,
                'status' => empty($subjectScores) ? 'empty' : 'ready',
            ];
        }

        if ($missingPreviousScoreCount > 0) {
            $warnings[] = "{$missingPreviousScoreCount} previous term subject score(s) were not found. Missing scores will be left blank and cumulative averages will use available terms only.";
        }

        return [
            'batch' => [
                'id' => (int) $batch->id,
                'term' => $batch->term,
                'session' => $batch->session,
                'status' => $batch->status,
            ],
            'summary' => [
                'students_found' => count($rows),
                'ready_rows' => collect($rows)->where('status', 'ready')->count(),
                'subjects_found' => count($subjectColumns),
                'errors_count' => count($errors),
                'warnings_count' => count($warnings),
                'can_import' => count($errors) === 0 && collect($rows)->where('status', 'ready')->count() > 0,
            ],
            'subjects' => array_values($subjectColumns),
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
            'report_column_policy' => $columnPolicy,
        ];
    }

    public function import(ResultBatch $batch, UploadedFile $file, ?int $departmentId = null): array
    {
        if (strtolower((string) $batch->status) === 'published') {
            throw ValidationException::withMessages([
                'batch' => 'This result has already been published. Reopen it before importing corrections.',
            ]);
        }

        $preview = $this->preview($batch, $file, $departmentId);
        if (! ($preview['summary']['can_import'] ?? false)) {
            throw ValidationException::withMessages([
                'file' => array_merge(['Import was not saved. Correct the errors and upload again.'], $preview['errors']),
            ]);
        }

        $readyRows = collect($preview['rows'])->where('status', 'ready')->values();
        $columnPolicy = $preview['report_column_policy'] ?? $this->resultColumnPolicy($batch);

        DB::transaction(function () use ($batch, $readyRows, $columnPolicy) {
            foreach ($readyRows as $row) {
                $student = DB::table('users')->where('id', $row['student_id'])->first();
                if (! $student) {
                    continue;
                }

                $studentResultId = DB::table('student_results_v2')
                    ->where('batch_id', $batch->id)
                    ->where('user_id', $student->id)
                    ->value('id');

                $header = [
                    'rollno' => $student->reg_no,
                    'department' => $student->department_id,
                    'section_id' => $student->section_id,
                    'updated_at' => now(),
                ];

                if ($studentResultId) {
                    DB::table('student_results_v2')->where('id', $studentResultId)->update($header);
                } else {
                    $studentResultId = DB::table('student_results_v2')->insertGetId(array_merge($header, [
                        'batch_id' => $batch->id,
                        'user_id' => $student->id,
                        'created_at' => now(),
                    ]));
                }

                foreach ($row['subjects'] as $subjectScore) {
                    $subjectResult = DB::table('subject_results_v2')
                        ->where('student_result_id', $studentResultId)
                        ->where('subject_id', $subjectScore['subject_id'])
                        ->first();

                    $carryOver = $this->buildAutoCarryOver(
                        $batch,
                        (int) $student->id,
                        (int) $subjectScore['subject_id'],
                        (float) $subjectScore['total'],
                        $columnPolicy
                    );

                    $payload = [
                        'ca_raw' => json_encode($subjectScore['ca']),
                        'ca_total' => $subjectScore['ca_total'],
                        'exam' => $subjectScore['exam'],
                        'total' => $subjectScore['total'],
                        'carry_over_json' => $carryOver ? json_encode($carryOver) : null,
                        'carry_over_enabled' => $carryOver ? 1 : 0,
                        'cumulative_total' => $carryOver['cumulative_total'] ?? null,
                        'cumulative_average' => $carryOver['cumulative_average'] ?? null,
                        'updated_at' => now(),
                    ];

                    if ($subjectResult) {
                        DB::table('subject_results_v2')->where('id', $subjectResult->id)->update($payload);
                        $subjectResultId = $subjectResult->id;
                    } else {
                        $subjectResultId = DB::table('subject_results_v2')->insertGetId(array_merge($payload, [
                            'student_result_id' => $studentResultId,
                            'subject_id' => $subjectScore['subject_id'],
                            'created_at' => now(),
                        ]));
                    }

                    DB::table('assessment_scores_v2')
                        ->where('subject_result_id', $subjectResultId)
                        ->delete();

                    foreach ($subjectScore['ca'] as $componentKey => $score) {
                        DB::table('assessment_scores_v2')->insert([
                            'subject_result_id' => $subjectResultId,
                            'component_key' => $componentKey,
                            'score' => $score,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });

        $computed = $this->computeService->computeBatch((int) $batch->id);
        UpdateResultSubmissionMonitorJob::dispatch((int) $batch->id)->afterCommit();
        ScanBatchResultAnomaliesJob::dispatch((int) $batch->id)->afterCommit();

        return [
            'message' => 'Results imported successfully.',
            'imported_students' => $readyRows->count(),
            'computed' => $computed,
            'preview' => $preview,
        ];
    }

    private function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['csv', 'txt', 'xls', 'xlsx'], true)) {
            throw ValidationException::withMessages([
                'file' => 'Upload a valid Excel or CSV file. Accepted formats are .xlsx, .xls, and .csv.',
            ]);
        }

        $contents = file_get_contents($file->getRealPath()) ?: '';

        if (in_array($extension, ['xls', 'xlsx'], true) && preg_match('/<table\b/i', $contents)) {
            $rows = $this->readHtmlTableRows($contents);
        } elseif (in_array($extension, ['csv', 'txt'], true)) {
            $rows = array_map('str_getcsv', file($file->getRealPath()) ?: []);
        } else {
            $import = new RawSheetImport();
            $sheets = Excel::toArray($import, $file);
            $rows = $sheets[0] ?? [];
        }

        if (empty($rows)) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is empty.']);
        }

        $headers = array_map(fn ($header) => $this->normalizeHeader($header), $rows[0] ?? []);
        $body = [];

        foreach (array_slice($rows, 1) as $row) {
            $assoc = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $assoc[$header] = $row[$index] ?? null;
            }
            $body[] = $assoc;
        }

        return [$headers, $body];
    }

    private function readHtmlTableRows(string $html): array
    {
        $rows = [];
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $html, $rowMatches);

        foreach ($rowMatches[1] ?? [] as $rowHtml) {
            preg_match_all('/<t[hd]\b[^>]*>(.*?)<\/t[hd]>/is', $rowHtml, $cellMatches);
            $row = [];

            foreach ($cellMatches[1] ?? [] as $cellHtml) {
                $row[] = trim(html_entity_decode(strip_tags($cellHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            if (! empty($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function mapSubjectColumns(array $headers, $subjects): array
    {
        $normalizedHeaders = array_flip($headers);
        $columns = [];

        foreach ($subjects as $subject) {
            $base = $this->normalizeHeader($subject->name);
            $examKey = $base . '_exam';
            $caColumns = [];

            if (array_key_exists($base . '_ca', $normalizedHeaders)) {
                $caColumns[] = [
                    'key' => $base . '_ca',
                    'component_key' => 'ca1',
                    'label' => 'CA',
                ];
            }

            for ($i = 1; $i <= 4; $i++) {
                foreach ([$base . '_ca_' . $i, $base . '_ca' . $i] as $candidate) {
                    if (array_key_exists($candidate, $normalizedHeaders)) {
                        $caColumns[] = [
                            'key' => $candidate,
                            'component_key' => 'ca' . $i,
                            'label' => 'CA ' . $i,
                        ];
                        break;
                    }
                }
            }

            $caColumns = collect($caColumns)->unique('component_key')->values()->all();

            if (! empty($caColumns) && array_key_exists($examKey, $normalizedHeaders)) {
                $columns[(int) $subject->id] = [
                    'id' => (int) $subject->id,
                    'name' => (string) $subject->name,
                    'ca' => $caColumns,
                    'exam' => $examKey,
                ];
            }
        }

        return $columns;
    }

    private function studentsForBatch(ResultBatch $batch, ?int $departmentId = null)
    {
        $query = DB::table('users')
            ->where('school_id', (int) $batch->school_id)
            ->where('level_id', (int) $batch->class_id)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->select('id', 'reg_no', 'firstname', 'surname', 'department_id', 'section_id');

        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }

        return $query
            ->orderBy('surname')
            ->orderBy('firstname')
            ->get();
    }

    private function subjectsForBatch(ResultBatch $batch, $students, ?int $departmentId = null)
    {
        $departmentIds = $departmentId
            ? [$departmentId]
            : collect($students)->pluck('department_id')->filter()->unique()->values()->all();

        $query = DB::table('subjects')
            ->where('school_id', (int) $batch->school_id)
            ->whereNull('archived_at')
            ->where(function ($query) use ($batch) {
                $query->where('class_id', (int) $batch->class_id)
                    ->orWhereNull('class_id');
            });

        if (! empty($departmentIds)) {
            $query->whereIn('department_id', $departmentIds);
        }

        return $query
            ->select('id', 'name')
            ->distinct()
            ->orderBy('name')
            ->get();
    }

    private function normalizeHeader($value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        return trim($value, '_');
    }

    private function assessmentComponents(string $format): array
    {
        return match (strtolower(trim($format))) {
            'ca_ca_exam', '2ca_exam' => [
                ['key' => 'ca1', 'label' => 'CA 1'],
                ['key' => 'ca2', 'label' => 'CA 2'],
            ],
            'ca_ca_ca_ca_exam', '4ca_exam' => [
                ['key' => 'ca1', 'label' => 'CA 1'],
                ['key' => 'ca2', 'label' => 'CA 2'],
                ['key' => 'ca3', 'label' => 'CA 3'],
                ['key' => 'ca4', 'label' => 'CA 4'],
            ],
            default => [
                ['key' => 'ca1', 'label' => 'CA'],
            ],
        };
    }

    private function resultColumnPolicy(ResultBatch $batch): array
    {
        $class = \App\Models\StudentClass::find((int) $batch->class_id);
        $sectionId = $class?->section_id;
        $setting = ResultTemplateSetting::firstOrCreate(
            ['school_id' => (int) $batch->school_id],
            ResultTemplateSetting::defaults((int) $batch->school_id)
        )->normalized();

        $columns = ResultTemplateSetting::DEFAULT_REPORT_COLUMN_OPTIONS;
        $rules = $setting['display_options']['report_column_rules'] ?? [];
        $matched = collect($rules)
            ->filter(function ($rule) use ($sectionId, $batch) {
                if (! is_array($rule)) {
                    return false;
                }

                $ruleSection = $rule['section_id'] ?? 'all';
                $sectionMatches = $ruleSection === 'all' || (string) $ruleSection === (string) $sectionId;
                $ruleTerm = strtolower(trim((string) ($rule['term'] ?? 'all')));
                $termMatches = $ruleTerm === 'all' || $ruleTerm === strtolower(trim((string) $batch->term));

                return $sectionMatches && $termMatches;
            })
            ->sortBy(function ($rule) {
                $sectionScore = (($rule['section_id'] ?? 'all') === 'all') ? 0 : 2;
                $termScore = strtolower(trim((string) ($rule['term'] ?? 'all'))) === 'all' ? 0 : 1;

                return $sectionScore + $termScore;
            });

        foreach ($matched as $rule) {
            $columns = array_merge($columns, is_array($rule['columns'] ?? null) ? $rule['columns'] : []);
        }

        return [
            'section_id' => $sectionId,
            'section_name' => $class?->section?->name,
            'term' => (string) $batch->term,
            'columns' => $columns,
            'carry_over_allowed' => (bool) (
                ($columns['show_first_term'] ?? false)
                || ($columns['show_second_term'] ?? false)
                || ($columns['show_cumulative_total'] ?? false)
                || ($columns['show_cumulative_average'] ?? false)
            ),
        ];
    }

    private function disallowedCarryOverHeaders(array $headers, array $policy): array
    {
        $columns = $policy['columns'] ?? [];
        $messages = [];

        $hasFirstTerm = collect($headers)->contains(fn ($header) => preg_match('/(^|_)first(_|$)|first_term|1st_term/', (string) $header));
        $hasSecondTerm = collect($headers)->contains(fn ($header) => preg_match('/(^|_)second(_|$)|second_term|2nd_term/', (string) $header));
        $hasCumulativeTotal = collect($headers)->contains(fn ($header) => str_contains((string) $header, 'cumulative_total') || str_contains((string) $header, 'cum_total'));
        $hasCumulativeAverage = collect($headers)->contains(fn ($header) => str_contains((string) $header, 'cumulative_average') || str_contains((string) $header, 'cum_avg') || str_contains((string) $header, 'cumm_avg'));

        if ($hasFirstTerm && empty($columns['show_first_term'])) {
            $messages[] = 'First Term columns are not enabled for this report-card setting. Remove them from the upload file or enable them in Result Design.';
        }

        if ($hasSecondTerm && empty($columns['show_second_term'])) {
            $messages[] = 'Second Term columns are not enabled for this report-card setting. Remove them from the upload file or enable them in Result Design.';
        }

        if ($hasCumulativeTotal && empty($columns['show_cumulative_total'])) {
            $messages[] = 'Cumulative Total columns are not enabled for this report-card setting. Remove them from the upload file or enable them in Result Design.';
        }

        if ($hasCumulativeAverage && empty($columns['show_cumulative_average'])) {
            $messages[] = 'Cumulative Average columns are not enabled for this report-card setting. Remove them from the upload file or enable them in Result Design.';
        }

        return $messages;
    }

    private function previousTermsForPolicy(ResultBatch $batch, array $policy): array
    {
        $columns = $policy['columns'] ?? [];
        if (
            empty($columns['show_first_term'])
            && empty($columns['show_second_term'])
            && empty($columns['show_cumulative_total'])
            && empty($columns['show_cumulative_average'])
        ) {
            return [];
        }

        $terms = DB::table('terms')
            ->where('school_id', (int) $batch->school_id)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('name')
            ->values();

        $currentIndex = $terms->search(fn ($term) => strcasecmp((string) $term, (string) $batch->term) === 0);
        if ($currentIndex === false) {
            return [];
        }

        return $terms
            ->slice(0, $currentIndex)
            ->filter(function ($term) use ($columns) {
                $normalized = strtolower((string) $term);

                if (str_contains($normalized, 'first')) {
                    return ! empty($columns['show_first_term'])
                        || ! empty($columns['show_cumulative_total'])
                        || ! empty($columns['show_cumulative_average']);
                }

                if (str_contains($normalized, 'second')) {
                    return ! empty($columns['show_second_term'])
                        || ! empty($columns['show_cumulative_total'])
                        || ! empty($columns['show_cumulative_average']);
                }

                return ! empty($columns['show_cumulative_total']) || ! empty($columns['show_cumulative_average']);
            })
            ->values()
            ->all();
    }

    private function previousSubjectScores(ResultBatch $batch, int $studentId, int $subjectId, array $terms): array
    {
        if (empty($terms)) {
            return [];
        }

        return DB::table('result_batches as rb')
            ->join('student_results_v2 as sr', 'sr.batch_id', '=', 'rb.id')
            ->join('subject_results_v2 as subr', 'subr.student_result_id', '=', 'sr.id')
            ->where('rb.school_id', (int) $batch->school_id)
            ->where('rb.class_id', (int) $batch->class_id)
            ->where('rb.session', (string) $batch->session)
            ->whereIn('rb.term', $terms)
            ->where('sr.user_id', $studentId)
            ->where('subr.subject_id', $subjectId)
            ->whereNotNull('subr.total')
            ->select('rb.term', 'subr.total')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->term => round((float) $row->total, 2)])
            ->all();
    }

    private function buildAutoCarryOver(ResultBatch $batch, int $studentId, int $subjectId, float $currentTotal, array $policy): ?array
    {
        $columns = $policy['columns'] ?? [];
        $previousTerms = $this->previousTermsForPolicy($batch, $policy);

        if (
            empty($previousTerms)
            && empty($columns['show_cumulative_total'])
            && empty($columns['show_cumulative_average'])
        ) {
            return null;
        }

        $previousScores = $this->previousSubjectScores($batch, $studentId, $subjectId, $previousTerms);
        $visibleTerms = [];

        foreach ($previousScores as $term => $score) {
            $normalized = strtolower((string) $term);
            if (str_contains($normalized, 'first') && ! empty($columns['show_first_term'])) {
                $visibleTerms[$term] = $score;
            }
            if (str_contains($normalized, 'second') && ! empty($columns['show_second_term'])) {
                $visibleTerms[$term] = $score;
            }
        }

        $scoresForAverage = array_values($previousScores);
        $scoresForAverage[] = $currentTotal;
        $cumulativeTotal = round(array_sum($scoresForAverage), 2);
        $termCount = count($scoresForAverage);

        return [
            'enabled' => true,
            'terms' => $visibleTerms,
            'current_term' => [(string) $batch->term => $currentTotal],
            'cumulative_total' => ! empty($columns['show_cumulative_total']) ? $cumulativeTotal : null,
            'cumulative_average' => ! empty($columns['show_cumulative_average']) && $termCount > 0
                ? round($cumulativeTotal / $termCount, 2)
                : null,
            'generated_by' => 'result_upload',
        ];
    }

    private function normalizeKey($value): string
    {
        return strtolower(trim((string) $value));
    }

    private function nullableNumber($value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
