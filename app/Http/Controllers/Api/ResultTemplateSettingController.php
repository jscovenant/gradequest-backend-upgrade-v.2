<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResultTemplateSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResultTemplateSettingController extends Controller
{
    private array $templates = [
        [
            'key' => 'classic_academic',
            'name' => 'Classic Academic',
            'description' => 'Formal report card with strong borders and a traditional school feel.',
        ],
        [
            'key' => 'modern_scholar',
            'name' => 'Modern Scholar',
            'description' => 'Clean colorful layout with soft sections and easier scanning.',
        ],
        [
            'key' => 'premium_letterhead',
            'name' => 'Premium Letterhead',
            'description' => 'Elegant certificate-style result with school branding emphasis.',
        ],
    ];

    public function show(Request $request): JsonResponse
    {
        $schoolId = (int) $request->user()->school_id;
        $setting = ResultTemplateSetting::firstOrCreate(
            ['school_id' => $schoolId],
            ResultTemplateSetting::defaults($schoolId)
        );

        return response()->json([
            'templates' => $this->templates,
            'setting' => $setting->normalized(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_key' => ['required', Rule::in(array_column($this->templates, 'key'))],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'background_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_family' => ['required', Rule::in(['Arial', 'Georgia', 'Times New Roman', 'Trebuchet MS'])],
            'display_options' => ['nullable', 'array'],
            'display_options.show_position' => ['boolean'],
            'display_options.show_grade' => ['boolean'],
            'display_options.show_remarks' => ['boolean'],
            'display_options.show_attendance' => ['boolean'],
            'display_options.show_domains' => ['boolean'],
            'display_options.show_qr_code' => ['boolean'],
            'display_options.show_signature' => ['boolean'],
            'display_options.show_student_photo' => ['boolean'],
            'display_options.show_watermark' => ['boolean'],
            'display_options.report_column_rules' => ['nullable', 'array'],
            'display_options.report_column_rules.*.id' => ['nullable', 'string', 'max:80'],
            'display_options.report_column_rules.*.section_id' => ['nullable'],
            'display_options.report_column_rules.*.section_name' => ['nullable', 'string', 'max:120'],
            'display_options.report_column_rules.*.term' => ['nullable', 'string', 'max:80'],
            'display_options.report_column_rules.*.columns' => ['nullable', 'array'],
            'display_options.report_column_rules.*.columns.show_position' => ['boolean'],
            'display_options.report_column_rules.*.columns.show_grade' => ['boolean'],
            'display_options.report_column_rules.*.columns.show_remarks' => ['boolean'],
            'display_options.report_column_rules.*.columns.show_first_term' => ['boolean'],
            'display_options.report_column_rules.*.columns.show_second_term' => ['boolean'],
            'display_options.report_column_rules.*.columns.show_cumulative_total' => ['boolean'],
            'display_options.report_column_rules.*.columns.show_cumulative_average' => ['boolean'],
        ]);

        $displayOptions = array_merge(
            ResultTemplateSetting::DEFAULT_DISPLAY_OPTIONS,
            $validated['display_options'] ?? []
        );
        $displayOptions['report_column_rules'] = collect($displayOptions['report_column_rules'] ?? [])
            ->filter(fn ($rule) => is_array($rule))
            ->map(function (array $rule) {
                return [
                    'id' => (string) ($rule['id'] ?? uniqid('rule_', true)),
                    'section_id' => $rule['section_id'] ?? null,
                    'section_name' => $rule['section_name'] ?? 'All sections',
                    'term' => $rule['term'] ?? 'all',
                    'columns' => array_merge(
                        ResultTemplateSetting::DEFAULT_REPORT_COLUMN_OPTIONS,
                        is_array($rule['columns'] ?? null) ? $rule['columns'] : []
                    ),
                ];
            })
            ->values()
            ->all();

        $schoolId = (int) $request->user()->school_id;
        $setting = ResultTemplateSetting::updateOrCreate(
            ['school_id' => $schoolId],
            [
                'template_key' => $validated['template_key'],
                'primary_color' => $validated['primary_color'],
                'secondary_color' => $validated['secondary_color'],
                'background_color' => $validated['background_color'],
                'font_family' => $validated['font_family'],
                'display_options' => $displayOptions,
                'updated_by' => $request->user()->id,
            ]
        );

        return response()->json([
            'message' => 'Result design saved successfully.',
            'setting' => $setting->normalized(),
        ]);
    }
}
