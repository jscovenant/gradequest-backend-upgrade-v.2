<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResultTemplateSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'display_options' => 'array',
    ];

    public const DEFAULT_DISPLAY_OPTIONS = [
        'show_position' => true,
        'show_grade' => true,
        'show_remarks' => true,
        'show_attendance' => true,
        'show_domains' => true,
        'show_qr_code' => true,
        'show_signature' => true,
        'show_student_photo' => true,
        'show_watermark' => true,
        'report_column_rules' => [],
    ];

    public const DEFAULT_REPORT_COLUMN_OPTIONS = [
        'show_position' => true,
        'show_grade' => true,
        'show_remarks' => true,
        'show_first_term' => false,
        'show_second_term' => false,
        'show_cumulative_total' => false,
        'show_cumulative_average' => false,
    ];

    public static function defaults(?int $schoolId = null): array
    {
        return [
            'school_id' => $schoolId,
            'template_key' => 'classic_academic',
            'primary_color' => '#0f3d7a',
            'secondary_color' => '#c9a84c',
            'background_color' => '#ffffff',
            'font_family' => 'Arial',
            'display_options' => self::DEFAULT_DISPLAY_OPTIONS,
        ];
    }

    public function normalized(): array
    {
        $data = $this->toArray();
        $data['display_options'] = array_merge(
            self::DEFAULT_DISPLAY_OPTIONS,
            is_array($this->display_options) ? $this->display_options : []
        );
        $data['display_options']['report_column_rules'] = collect($data['display_options']['report_column_rules'] ?? [])
            ->filter(fn ($rule) => is_array($rule))
            ->map(function (array $rule) {
                return array_merge($rule, [
                    'columns' => array_merge(
                        self::DEFAULT_REPORT_COLUMN_OPTIONS,
                        is_array($rule['columns'] ?? null) ? $rule['columns'] : []
                    ),
                ]);
            })
            ->values()
            ->all();

        return $data;
    }
}
