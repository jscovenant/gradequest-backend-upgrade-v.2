<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BroadsheetV2Service
{
    public function build(int $batchId, bool $includePrevious, string $rankBy = 'average'): array
    {
        $batch = DB::table('result_batches')->where('id', $batchId)->first();
        if (!$batch) {
            throw ValidationException::withMessages(['batch' => 'Batch not found']);
        }

        $studentResults = DB::table('student_results_v2 as sr')
            ->join('users as u', 'u.id', '=', 'sr.user_id')
            ->where('sr.batch_id', $batchId)
            ->select([
                'sr.id as student_result_id',
                'sr.user_id',
                'sr.rollno',
                'sr.section_id',
                'sr.department',
                'sr.position as saved_position',
                'sr.class_size as saved_class_size',
                'sr.total_grade',
                'sr.total_average',
                'sr.meta_json',
                'u.reg_no',
                'u.firstname',
                'u.surname',
            ])
            ->orderBy('u.surname')
            ->orderBy('u.firstname')
            ->get();

        $srIds = $studentResults->pluck('student_result_id')->all();
        $subjects = $this->loadSubjectsForBatch($batch, $srIds);
        $subjectIds = array_map(fn($s) => $s['id'], $subjects);

        $subjectResults = collect();
        if (!empty($srIds) && !empty($subjectIds)) {
            $subjectResults = DB::table('subject_results_v2')
                ->whereIn('student_result_id', $srIds)
                ->whereIn('subject_id', $subjectIds)
                ->select([
                    'student_result_id',
                    'subject_id',
                    'ca_raw',
                    'ca_total',
                    'exam',
                    'total',
                    'grade',
                    'remark',
                    'subject_position',
                    'comment',
                    'signature',
                    'carry_over_enabled',
                    'carry_over_json',
                    'cumulative_total',
                    'cumulative_average',
                ])
                ->get();
        }

        $cellsByStudent = [];
        foreach ($subjectResults as $r) {
            $cellsByStudent[$r->student_result_id][(string)$r->subject_id] = $this->mapSubjectCell($r, $includePrevious);
        }

        $rows = [];
        foreach ($studentResults as $sr) {
            $row = [
                'user_id' => (int)$sr->user_id,
                'student_result_id' => (int)$sr->student_result_id,
                'reg_no' => $sr->reg_no ?? $sr->rollno,
                'name' => trim(($sr->surname ?? '') . ' ' . ($sr->firstname ?? '')),
                'overall' => [
                    'average' => $this->toFloat($sr->total_average),
                    'grade' => $sr->total_grade,
                    'position' => null,
                ],
                'subjects' => $cellsByStudent[$sr->student_result_id] ?? [],
            ];

            foreach ($subjects as $s) {
                $sid = (string)$s['id'];
                if (!isset($row['subjects'][$sid])) {
                    $row['subjects'][$sid] = $this->emptyCell();
                }
            }

            $rows[] = $row;
        }

        $this->rankOverall($rows, $rankBy);
        $this->rankPerSubject($rows, $subjects);

        return [
            'batch' => [
                'id' => (int)$batch->id,
                'school_id' => (int)$batch->school_id,
                'class_id' => (int)$batch->class_id,
                'term' => (string)$batch->term,
                'session' => (string)$batch->session,
                'status' => (string)$batch->status,
            ],
            'subjects' => $subjects,
            'rows' => $rows,
            'meta' => [
                'class_size' => count($rows),
                'include_previous' => $includePrevious,
                'rank_by' => $rankBy,
            ],
        ];
    }

    public function computeAndPersist(int $batchId, bool $includePrevious, string $rankBy = 'average'): array
    {
        $data = $this->build($batchId, $includePrevious, $rankBy);
        $rows = $data['rows'];
        $classSize = count($rows);

        DB::transaction(function () use ($batchId, $rows, $classSize) {
            foreach ($rows as $r) {
                DB::table('student_results_v2')
                    ->where('id', $r['student_result_id'])
                    ->update([
                        'position' => (string)($r['overall']['position'] ?? ''),
                        'class_size' => (string)$classSize,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('result_batches')
                ->where('id', $batchId)
                ->update([
                    'status' => 'computed',
                    'updated_at' => now(),
                ]);
        });

        $data['batch']['status'] = 'computed';
        return $data;
    }

    public function buildStudent(int $batchId, int $studentId, bool $includePrevious): array
    {
        $data = $this->build($batchId, $includePrevious, 'average');

        foreach ($data['rows'] as $row) {
            if ((int)$row['user_id'] === (int)$studentId) {
                return $row;
            }
        }

        throw ValidationException::withMessages(['student' => 'Student not found in this batch']);
    }

    public function export(array $data, string $format)
    {
        $format = strtolower($format);
        if (!in_array($format, ['csv', 'xls', 'xlsx', 'excel'], true)) $format = 'csv';

        $subjects = $data['subjects'];
        $rows = $data['rows'];

        $headers = ['Reg No', 'Name'];
        foreach ($subjects as $subject) {
            $headers[] = $subject['name'] . ' CA';
            $headers[] = $subject['name'] . ' Exam';
            $headers[] = $subject['name'] . ' Total';
            $headers[] = $subject['name'] . ' Grade';
        }
        $headers[] = 'Total Score';
        $headers[] = 'Average';
        $headers[] = 'Overall Grade';
        $headers[] = 'Overall Position';

        $lines = [];
        foreach ($rows as $r) {
            $line = [$r['reg_no'], $r['name']];
            $totalScore = 0;

            foreach ($subjects as $s) {
                $sid = (string)$s['id'];
                $cell = $r['subjects'][$sid] ?? [];
                $subjectTotal = $cell['effective_total'] ?? $cell['total'] ?? null;
                if (is_numeric($subjectTotal)) {
                    $totalScore += (float)$subjectTotal;
                }

                $line[] = $cell['ca_total'] ?? null;
                $line[] = $cell['exam'] ?? null;
                $line[] = $subjectTotal;
                $line[] = $cell['grade'] ?? null;
            }

            $line[] = $totalScore;
            $line[] = $r['overall']['average'];
            $line[] = $r['overall']['grade'];
            $line[] = $r['overall']['position'];
            $lines[] = $line;
        }

        if (in_array($format, ['xls', 'xlsx', 'excel'], true)) {
            $fileName = 'broadsheet_batch_' . ($data['batch']['id'] ?? 'x') . '.xls';

            $html = '<html><head><meta charset="UTF-8"></head><body><table border="1">';
            $html .= '<tr>';
            foreach ($headers as $header) {
                $html .= '<th>' . htmlspecialchars((string)$header, ENT_QUOTES, 'UTF-8') . '</th>';
            }
            $html .= '</tr>';

            foreach ($lines as $line) {
                $html .= '<tr>';
                foreach ($line as $cell) {
                    $html .= '<td>' . htmlspecialchars((string)($cell ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</table></body></html>';

            return response($html, 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]);
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers);

        foreach ($lines as $line) {
            fputcsv($out, $line);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $fileName = 'broadsheet_batch_' . ($data['batch']['id'] ?? 'x') . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    // ------------------ internals ------------------

    private function loadSubjectsForBatch(object $batch, array $studentResultIds = []): array
    {
        if (!empty($studentResultIds)) {
            $subjectsFromSavedResults = DB::table('subject_results_v2 as sr')
                ->join('subjects as s', 's.id', '=', 'sr.subject_id')
                ->whereIn('sr.student_result_id', $studentResultIds)
                ->select(['s.id', 's.name'])
                ->distinct()
                ->orderBy('s.name')
                ->get()
                ->map(fn($s) => ['id' => (int)$s->id, 'name' => (string)$s->name])
                ->values()
                ->all();

            if (!empty($subjectsFromSavedResults)) {
                return $subjectsFromSavedResults;
            }
        }

        return DB::table('subjects')
            ->where('class_id', (int)$batch->class_id)
            ->where(function ($qq) use ($batch) {
                $qq->whereNull('school_id')->orWhere('school_id', (int)$batch->school_id);
            })
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(fn($s) => ['id' => (int)$s->id, 'name' => (string)$s->name])
            ->values()
            ->all();
    }

    private function mapSubjectCell(object $r, bool $includePrevious): array
    {
        $total = $this->toFloat($r->total);
        $exam = $this->toFloat($r->exam);
        $carryEnabled = (int)$r->carry_over_enabled === 1;

        $effectiveTotal = $total;
        if ($includePrevious && $carryEnabled && $r->cumulative_total !== null) {
            $effectiveTotal = (float)$r->cumulative_total;
        }

        return [
            'ca' => $this->decodeMaybeJson($r->ca_raw),
            'ca_total' => $r->ca_total !== null ? (float)$r->ca_total : null,
            'exam' => $exam,
            'total' => $total,
            'effective_total' => $effectiveTotal,
            'grade' => $r->grade,
            'remark' => $r->remark,
            'comment' => $r->comment,
            'signature' => $r->signature,
            'carry_over_enabled' => $carryEnabled,
            'carry_over_json' => $this->decodeMaybeJson($r->carry_over_json),
            'cumulative_total' => $r->cumulative_total !== null ? (float)$r->cumulative_total : null,
            'cumulative_average' => $r->cumulative_average !== null ? (float)$r->cumulative_average : null,
            'position' => $r->subject_position !== null ? (string)$r->subject_position : null,
        ];
    }

    private function emptyCell(): array
    {
        return [
            'ca' => null,
            'ca_total' => null,
            'exam' => null,
            'total' => null,
            'effective_total' => null,
            'grade' => null,
            'remark' => null,
            'comment' => null,
            'signature' => null,
            'carry_over_enabled' => false,
            'carry_over_json' => null,
            'cumulative_total' => null,
            'cumulative_average' => null,
            'position' => null,
        ];
    }

    private function rankOverall(array &$rows, string $rankBy): void
    {
        $scores = [];
        foreach ($rows as $i => $r) {
            $scores[$i] = $r['overall']['average'] ?? -INF;
        }

        arsort($scores);
        $pos = 0; $rank = 0; $last = null;

        foreach ($scores as $idx => $val) {
            $pos++;
            if ($last === null || $val !== $last) $rank = $pos;
            $rows[$idx]['overall']['position'] = $rank;
            $last = $val;
        }
    }

    private function rankPerSubject(array &$rows, array $subjects): void
    {
        foreach ($subjects as $s) {
            $sid = (string)$s['id'];
            $scores = [];

            foreach ($rows as $i => $r) {
                $cell = $r['subjects'][$sid] ?? null;
                $scores[$i] = ($cell && $cell['effective_total'] !== null) ? (float)$cell['effective_total'] : -INF;
            }

            arsort($scores);
            $pos = 0; $rank = 0; $last = null;

            foreach ($scores as $idx => $val) {
                $pos++;
                if ($last === null || $val !== $last) $rank = $pos;
                $rows[$idx]['subjects'][$sid]['position'] = $rank;
                $last = $val;
            }
        }
    }

    private function decodeMaybeJson($val)
    {
        if ($val === null) return null;
        if (!is_string($val)) return $val;

        $decoded = json_decode($val, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $val;
    }

    private function toFloat($val): ?float
    {
        if ($val === null) return null;
        if (is_numeric($val)) return (float)$val;
        return null;
    }
}
