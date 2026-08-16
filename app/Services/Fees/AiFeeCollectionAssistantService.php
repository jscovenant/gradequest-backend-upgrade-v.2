<?php

namespace App\Services\Fees;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AiFeeCollectionAssistantService
{
    public function analyze(int $schoolId, array $filters = []): array
    {
        $dataset = $this->dataset($schoolId, $filters);

        if (($dataset['summary']['total_balance'] ?? 0) <= 0) {
            return [
                'analysis' => $this->emptyAnalysis($dataset),
                'usage' => ['model' => config('openai.model'), 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            ];
        }

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
            ->post('https://api.openai.com/v1/responses', $this->payload($dataset, $filters));

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'OpenAI could not analyze fee collection at this time.');
        }

        $body = $response->json();
        $analysis = json_decode($this->extractOutputJson($body), true);

        if (! is_array($analysis)) {
            throw new RuntimeException('OpenAI returned an invalid fee collection response. Try again.');
        }

        return [
            'analysis' => $this->normalizeAnalysis($analysis, $dataset),
            'usage' => [
                'model' => $body['model'] ?? config('openai.model'),
                'input_tokens' => (int) data_get($body, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($body, 'usage.output_tokens', 0),
                'total_tokens' => (int) data_get($body, 'usage.total_tokens', 0),
            ],
        ];
    }

    public function dataset(int $schoolId, array $filters = []): array
    {
        $query = DB::table('student_fees as sf')
            ->join('users as st', 'st.id', '=', 'sf.student_id')
            ->leftJoin('parent_students as ps', function ($join) use ($schoolId) {
                $join->on('ps.student_id', '=', 'sf.student_id')
                    ->where('ps.school_id', '=', $schoolId);
            })
            ->leftJoin('users as p', 'p.id', '=', 'ps.parent_id')
            ->leftJoin('fee_types as ft', 'ft.id', '=', 'sf.fee_type_id')
            ->leftJoin('student_classes as l', 'l.id', '=', 'st.level_id')
            ->leftJoin('sections as sec', 'sec.id', '=', 'sf.section_id')
            ->leftJoin('academic_sessions as s', 's.id', '=', 'sf.session_id')
            ->leftJoin('terms as t', 't.id', '=', 'sf.term_id')
            ->where('sf.school_id', $schoolId)
            ->where('sf.balance', '>', 0);

        foreach (['session_id', 'term_id', 'class_id', 'section_id'] as $key) {
            if (! empty($filters[$key])) {
                $column = match ($key) {
                    'class_id' => 'st.level_id',
                    default => 'sf.' . $key,
                };
                $query->where($column, (int) $filters[$key]);
            }
        }

        $rows = $query
            ->select([
                'sf.id', 'sf.student_id', 'sf.total_amount', 'sf.amount_paid', 'sf.balance', 'sf.status', 'sf.updated_at',
                'st.firstname as student_firstname', 'st.surname as student_surname', 'st.reg_no as student_reg_no',
                'p.id as parent_id', 'p.firstname as parent_firstname', 'p.surname as parent_surname', 'p.phone as parent_phone', 'p.whatsapp_no as parent_whatsapp_no',
                'ft.name as fee_name', 'l.name as class_name', 'sec.name as section_name', 's.name as session_name', 't.name as term_name',
            ])
            ->orderByDesc('sf.balance')
            ->limit(500)
            ->get();

        $parents = $rows->groupBy(fn ($row) => (string) ($row->parent_id ?: 'unassigned:' . $row->student_id))->map(function ($items) {
            $first = $items->first();
            $balance = (float) $items->sum('balance');
            $total = (float) $items->sum('total_amount');
            $paid = (float) $items->sum('amount_paid');
            $studentCount = $items->pluck('student_id')->unique()->count();
            $unpaidItems = $items->where('status', 'unpaid')->count();
            $partialItems = $items->where('status', 'partial')->count();
            $riskScore = $this->riskScore($balance, $total, $unpaidItems, $partialItems, $studentCount, filled($first->parent_phone ?: $first->parent_whatsapp_no));

            return [
                'parent_id' => $first->parent_id ? (int) $first->parent_id : null,
                'parent_name' => trim(($first->parent_firstname ?? '') . ' ' . ($first->parent_surname ?? '')) ?: 'Parent/Guardian not assigned',
                'phone' => $first->parent_whatsapp_no ?: $first->parent_phone,
                'children_count' => $studentCount,
                'fee_items_count' => $items->count(),
                'total_amount' => $total,
                'amount_paid' => $paid,
                'balance' => $balance,
                'risk_score' => $riskScore,
                'risk_level' => $riskScore >= 75 ? 'high' : ($riskScore >= 45 ? 'medium' : 'low'),
                'students' => $items->groupBy('student_id')->map(fn ($studentFees) => [
                    'student_id' => (int) $studentFees->first()->student_id,
                    'student_name' => trim(($studentFees->first()->student_firstname ?? '') . ' ' . ($studentFees->first()->student_surname ?? '')),
                    'reg_no' => $studentFees->first()->student_reg_no,
                    'class_name' => $studentFees->first()->class_name,
                    'balance' => (float) $studentFees->sum('balance'),
                    'fees' => $studentFees->map(fn ($fee) => [
                        'fee_name' => $fee->fee_name ?: 'School fee',
                        'term' => $fee->term_name,
                        'session' => $fee->session_name,
                        'balance' => (float) $fee->balance,
                        'status' => $fee->status,
                    ])->values()->all(),
                ])->values()->all(),
            ];
        })->sortByDesc('risk_score')->values();

        $summary = [
            'total_balance' => (float) $rows->sum('balance'),
            'total_amount' => (float) $rows->sum('total_amount'),
            'amount_paid' => (float) $rows->sum('amount_paid'),
            'owing_students' => $rows->pluck('student_id')->unique()->count(),
            'owing_parents' => $parents->count(),
            'fee_items' => $rows->count(),
            'high_risk_parents' => $parents->where('risk_level', 'high')->count(),
        ];

        return [
            'summary' => $summary,
            'at_risk_parents' => $parents->take((int) ($filters['limit'] ?? 10))->values()->all(),
            'breakdowns' => [
                'by_class' => $this->breakdown($rows, 'class_name'),
                'by_parent' => $parents->map(fn ($parent) => ['label' => $parent['parent_name'], 'balance' => $parent['balance'], 'count' => $parent['fee_items_count']])->take(20)->values()->all(),
                'by_term' => $this->breakdown($rows, 'term_name'),
                'by_student' => $this->studentBreakdown($rows),
            ],
        ];
    }

    private function payload(array $dataset, array $filters): array
    {
        $instructions = <<<'PROMPT'
You are a school fee collection assistant for a Nigerian/African school management system.
Return only valid JSON. Do not include markdown.

Rules:
- Be polite, professional, and parent-friendly.
- Do not shame parents or use aggressive language.
- Do not invent balances. Use only provided numbers.
- Give practical collection recommendations for school admin/bursar.
- Reminder messages must be ready to send by SMS or WhatsApp.
- Do not mention AI.

Return exactly this JSON shape:
{
  "executive_summary": "Short summary for the school admin",
  "collection_priorities": ["Actionable priority"],
  "risk_notes": ["Short risk insight"],
  "parent_messages": [
    {"parent_id": 1, "parent_name": "Name", "risk_level": "high", "message": "Polite reminder text"}
  ]
}
PROMPT;

        $context = [
            'filters' => $filters,
            'summary' => $dataset['summary'],
            'at_risk_parents' => $dataset['at_risk_parents'],
            'breakdowns' => $dataset['breakdowns'],
        ];

        return [
            'model' => config('openai.model', 'gpt-5-mini'),
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $instructions]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode($context, JSON_PRETTY_PRINT)]]],
            ],
            'text' => ['format' => ['type' => 'json_object']],
        ];
    }

    private function normalizeAnalysis(array $analysis, array $dataset): array
    {
        return [
            'summary' => $dataset['summary'],
            'breakdowns' => $dataset['breakdowns'],
            'at_risk_parents' => $dataset['at_risk_parents'],
            'executive_summary' => Str::limit(trim((string) ($analysis['executive_summary'] ?? 'Outstanding fee analysis completed.')), 800, ''),
            'collection_priorities' => $this->stringList($analysis['collection_priorities'] ?? []),
            'risk_notes' => $this->stringList($analysis['risk_notes'] ?? []),
            'parent_messages' => collect($analysis['parent_messages'] ?? [])->map(fn ($item) => [
                'parent_id' => isset($item['parent_id']) ? (int) $item['parent_id'] : null,
                'parent_name' => trim((string) ($item['parent_name'] ?? 'Parent/Guardian')),
                'risk_level' => trim((string) ($item['risk_level'] ?? 'medium')),
                'message' => trim((string) ($item['message'] ?? '')),
            ])->filter(fn ($item) => $item['message'] !== '')->values()->all(),
        ];
    }

    private function emptyAnalysis(array $dataset): array
    {
        return [
            'summary' => $dataset['summary'],
            'breakdowns' => $dataset['breakdowns'],
            'at_risk_parents' => [],
            'executive_summary' => 'No outstanding school-fee balance was found for the selected period.',
            'collection_priorities' => ['Keep fee records updated and continue sending receipts promptly.'],
            'risk_notes' => [],
            'parent_messages' => [],
        ];
    }

    private function riskScore(float $balance, float $total, int $unpaidItems, int $partialItems, int $studentCount, bool $hasContact): int
    {
        $ratio = $total > 0 ? min(1, $balance / $total) : 1;
        $score = (int) round($ratio * 55) + min(20, $unpaidItems * 6) + min(10, $partialItems * 3) + min(10, max(0, $studentCount - 1) * 5);
        if (! $hasContact) {
            $score += 10;
        }

        return max(0, min(100, $score));
    }

    private function breakdown($rows, string $field): array
    {
        return $rows->groupBy(fn ($row) => $row->{$field} ?: 'Not set')
            ->map(fn ($items, $label) => ['label' => $label, 'balance' => (float) $items->sum('balance'), 'count' => $items->count()])
            ->sortByDesc('balance')
            ->values()
            ->all();
    }

    private function studentBreakdown($rows): array
    {
        return $rows->groupBy('student_id')
            ->map(function ($items) {
                $first = $items->first();
                return [
                    'student_id' => (int) $first->student_id,
                    'student_name' => trim(($first->student_firstname ?? '') . ' ' . ($first->student_surname ?? '')),
                    'reg_no' => $first->student_reg_no,
                    'class_name' => $first->class_name,
                    'balance' => (float) $items->sum('balance'),
                    'count' => $items->count(),
                ];
            })
            ->sortByDesc('balance')
            ->take(30)
            ->values()
            ->all();
    }

    private function stringList($items): array
    {
        return collect(is_array($items) ? $items : [$items])->map(fn ($item) => trim((string) $item))->filter()->values()->all();
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

