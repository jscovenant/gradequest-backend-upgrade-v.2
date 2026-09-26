<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\GradingScale;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GradingScaleController extends Controller
{
    /**
     * Standard built-in grading presets
     */
    public static function getPresets(): array
    {
        return [
            'standard_secondary' => [
                'name' => 'Standard Secondary (A – F Scale)',
                'description' => 'Common secondary school grading system with letter grades and remarks.',
                'scales' => [
                    ['min' => 70, 'max' => 100, 'grade' => 'A', 'remark' => 'Excellent', 'gpa_point' => 5.0, 'color' => '#10B981'],
                    ['min' => 60, 'max' => 69.99, 'grade' => 'B', 'remark' => 'Very Good', 'gpa_point' => 4.0, 'color' => '#3B82F6'],
                    ['min' => 50, 'max' => 59.99, 'grade' => 'C', 'remark' => 'Credit', 'gpa_point' => 3.0, 'color' => '#F59E0B'],
                    ['min' => 45, 'max' => 49.99, 'grade' => 'D', 'remark' => 'Pass', 'gpa_point' => 2.0, 'color' => '#8B5CF6'],
                    ['min' => 40, 'max' => 44.99, 'grade' => 'E', 'remark' => 'Fair', 'gpa_point' => 1.0, 'color' => '#EC4899'],
                    ['min' => 0, 'max' => 39.99, 'grade' => 'F', 'remark' => 'Fail', 'gpa_point' => 0.0, 'color' => '#EF4444'],
                ],
            ],
            'waec_9point' => [
                'name' => 'WAEC / NECO Standard (9-Point Scale)',
                'description' => 'Official Nigerian Senior Secondary Examination 9-point scale.',
                'scales' => [
                    ['min' => 75, 'max' => 100, 'grade' => 'A1', 'remark' => 'Excellent', 'gpa_point' => 9.0, 'color' => '#10B981'],
                    ['min' => 70, 'max' => 74.99, 'grade' => 'B2', 'remark' => 'Very Good', 'gpa_point' => 8.0, 'color' => '#059669'],
                    ['min' => 65, 'max' => 69.99, 'grade' => 'B3', 'remark' => 'Good', 'gpa_point' => 7.0, 'color' => '#3B82F6'],
                    ['min' => 60, 'max' => 64.99, 'grade' => 'C4', 'remark' => 'Credit', 'gpa_point' => 6.0, 'color' => '#2563EB'],
                    ['min' => 55, 'max' => 59.99, 'grade' => 'C5', 'remark' => 'Credit', 'gpa_point' => 5.0, 'color' => '#6366F1'],
                    ['min' => 50, 'max' => 54.99, 'grade' => 'C6', 'remark' => 'Credit', 'gpa_point' => 4.0, 'color' => '#F59E0B'],
                    ['min' => 45, 'max' => 49.99, 'grade' => 'D7', 'remark' => 'Pass', 'gpa_point' => 3.0, 'color' => '#D97706'],
                    ['min' => 40, 'max' => 44.99, 'grade' => 'E8', 'remark' => 'Pass', 'gpa_point' => 2.0, 'color' => '#EC4899'],
                    ['min' => 0, 'max' => 39.99, 'grade' => 'F9', 'remark' => 'Fail', 'gpa_point' => 0.0, 'color' => '#EF4444'],
                ],
            ],
            'primary_formative' => [
                'name' => 'Primary / Formative Scale',
                'description' => 'Descriptive mastery levels suitable for basic & primary education.',
                'scales' => [
                    ['min' => 80, 'max' => 100, 'grade' => 'Distinction', 'remark' => 'Outstanding Performance', 'gpa_point' => 4.0, 'color' => '#10B981'],
                    ['min' => 65, 'max' => 79.99, 'grade' => 'Credit', 'remark' => 'Above Average', 'gpa_point' => 3.0, 'color' => '#3B82F6'],
                    ['min' => 50, 'max' => 64.99, 'grade' => 'Merit', 'remark' => 'Satisfactory', 'gpa_point' => 2.0, 'color' => '#F59E0B'],
                    ['min' => 40, 'max' => 49.99, 'grade' => 'Pass', 'remark' => 'Needs Guidance', 'gpa_point' => 1.0, 'color' => '#EC4899'],
                    ['min' => 0, 'max' => 39.99, 'grade' => 'Developing', 'remark' => 'Needs Significant Improvement', 'gpa_point' => 0.0, 'color' => '#EF4444'],
                ],
            ],
            'tertiary_gpa' => [
                'name' => 'Higher Education (5.0 GPA Scale)',
                'description' => 'University and polytechnic style 5.0 grade point average scale.',
                'scales' => [
                    ['min' => 70, 'max' => 100, 'grade' => 'A', 'remark' => 'First Class / Excellent', 'gpa_point' => 5.0, 'color' => '#10B981'],
                    ['min' => 60, 'max' => 69.99, 'grade' => 'B', 'remark' => 'Second Class Upper / Very Good', 'gpa_point' => 4.0, 'color' => '#3B82F6'],
                    ['min' => 50, 'max' => 59.99, 'grade' => 'C', 'remark' => 'Second Class Lower / Good', 'gpa_point' => 3.0, 'color' => '#F59E0B'],
                    ['min' => 45, 'max' => 49.99, 'grade' => 'D', 'remark' => 'Third Class / Fair', 'gpa_point' => 2.0, 'color' => '#8B5CF6'],
                    ['min' => 40, 'max' => 44.99, 'grade' => 'E', 'remark' => 'Pass', 'gpa_point' => 1.0, 'color' => '#EC4899'],
                    ['min' => 0, 'max' => 39.99, 'grade' => 'F', 'remark' => 'Fail', 'gpa_point' => 0.0, 'color' => '#EF4444'],
                ],
            ],
        ];
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $schoolId = (int) $user->school_id;

        $sections = Section::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->with(['gradingScales' => function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId)->orderByRaw('CAST(min AS DECIMAL(8,2)) DESC');
            }])
            ->orderBy('name')
            ->get();

        // If a section has no custom scales yet, seed with sensible defaults based on section name
        $presets = self::getPresets();

        $sectionsData = $sections->map(function ($sec) use ($schoolId, $presets) {
            $scales = $sec->gradingScales;

            if ($scales->isEmpty()) {
                $secNameLower = strtolower($sec->name);
                $presetKey = 'standard_secondary';

                if (str_contains($secNameLower, 'senior') || str_contains($secNameLower, 'ss')) {
                    $presetKey = 'waec_9point';
                } elseif (str_contains($secNameLower, 'primary') || str_contains($secNameLower, 'nursery') || str_contains($secNameLower, 'creche') || str_contains($secNameLower, 'basic')) {
                    $presetKey = 'primary_formative';
                }

                $presetScales = $presets[$presetKey]['scales'];
                
                // Return formatted default preview items
                $previewScales = collect($presetScales)->map(function ($item, $idx) use ($sec, $schoolId) {
                    return [
                        'id' => null,
                        'school_id' => $schoolId,
                        'section_id' => $sec->id,
                        'min' => (float) $item['min'],
                        'max' => (float) $item['max'],
                        'grade' => $item['grade'],
                        'remark' => $item['remark'],
                        'gpa_point' => (float) ($item['gpa_point'] ?? 0),
                        'color' => $item['color'] ?? '#3B82F6',
                        'sort_order' => $idx,
                    ];
                });

                return [
                    'id' => $sec->id,
                    'name' => $sec->name,
                    'uses_grading_scale' => (bool) ($sec->uses_grading_scale ?? true),
                    'grading_system_type' => $sec->grading_system_type ?? $presetKey,
                    'is_customized' => false,
                    'grading_scales' => $previewScales,
                ];
            }

            return [
                'id' => $sec->id,
                'name' => $sec->name,
                'uses_grading_scale' => (bool) ($sec->uses_grading_scale ?? true),
                'grading_system_type' => $sec->grading_system_type ?? 'custom',
                'is_customized' => true,
                'grading_scales' => $scales->map(fn ($s) => [
                    'id' => $s->id,
                    'school_id' => $s->school_id,
                    'section_id' => $s->section_id,
                    'min' => (float) $s->min,
                    'max' => (float) $s->max,
                    'grade' => $s->grade,
                    'remark' => $s->remark,
                    'gpa_point' => (float) ($s->gpa_point ?? 0),
                    'color' => $s->color ?? '#3B82F6',
                    'sort_order' => (int) $s->sort_order,
                ]),
            ];
        });

        return response()->json([
            'sections' => $sectionsData,
            'presets' => $presets,
        ]);
    }

    public function updateSectionGrading(Request $request, $sectionId)
    {
        $user = Auth::user();
        $schoolId = (int) $user->school_id;

        $section = Section::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->findOrFail($sectionId);

        $request->validate([
            'uses_grading_scale' => 'required|boolean',
            'grading_system_type' => 'nullable|string|max:50',
            'scales' => 'nullable|array',
            'scales.*.min' => 'required|numeric|min:0|max:100',
            'scales.*.max' => 'required|numeric|min:0|max:100',
            'scales.*.grade' => 'required|string|max:50',
            'scales.*.remark' => 'nullable|string|max:100',
            'scales.*.gpa_point' => 'nullable|numeric|min:0|max:10',
            'scales.*.color' => 'nullable|string|max:30',
        ]);

        DB::transaction(function () use ($section, $schoolId, $request) {
            // Update section toggle
            $section->uses_grading_scale = (bool) $request->input('uses_grading_scale', true);
            if ($request->filled('grading_system_type')) {
                $section->grading_system_type = $request->input('grading_system_type');
            }
            $section->save();

            // Replace grading scale rows
            GradingScale::where('school_id', $schoolId)
                ->where('section_id', $section->id)
                ->delete();

            if ($request->has('scales') && is_array($request->scales)) {
                $sortOrder = 0;
                foreach ($request->scales as $row) {
                    GradingScale::create([
                        'school_id' => $schoolId,
                        'section_id' => $section->id,
                        'min' => (string) $row['min'],
                        'max' => (string) $row['max'],
                        'grade' => strtoupper(trim($row['grade'])),
                        'remark' => trim($row['remark'] ?? ''),
                        'gpa_point' => isset($row['gpa_point']) && is_numeric($row['gpa_point']) ? (float) $row['gpa_point'] : null,
                        'color' => $row['color'] ?? null,
                        'sort_order' => $sortOrder++,
                    ]);
                }
            }
        });

        return response()->json([
            'message' => "Grading scale for section '{$section->name}' updated successfully.",
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'uses_grading_scale' => (bool) $section->uses_grading_scale,
                'grading_system_type' => $section->grading_system_type,
            ],
        ]);
    }

    public function toggleSectionGrading(Request $request, $sectionId)
    {
        $user = Auth::user();
        $schoolId = (int) $user->school_id;

        $section = Section::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->findOrFail($sectionId);

        $usesGrading = $request->has('uses_grading_scale')
            ? (bool) $request->input('uses_grading_scale')
            : !$section->uses_grading_scale;

        $section->uses_grading_scale = $usesGrading;
        $section->save();

        $statusText = $usesGrading ? "enabled" : "disabled (no letter grades on report cards)";

        return response()->json([
            'message' => "Grading scale {$statusText} for section '{$section->name}'.",
            'uses_grading_scale' => $usesGrading,
        ]);
    }

    public function applyPreset(Request $request, $sectionId)
    {
        $user = Auth::user();
        $schoolId = (int) $user->school_id;

        $section = Section::where('school_id', $schoolId)
            ->whereNull('archived_at')
            ->findOrFail($sectionId);

        $request->validate([
            'preset_key' => 'required|string',
        ]);

        $presets = self::getPresets();
        $key = $request->input('preset_key');

        if (!isset($presets[$key])) {
            return response()->json(['message' => 'Invalid preset template selected.'], 422);
        }

        $preset = $presets[$key];

        DB::transaction(function () use ($section, $schoolId, $key, $preset) {
            $section->uses_grading_scale = true;
            $section->grading_system_type = $key;
            $section->save();

            GradingScale::where('school_id', $schoolId)
                ->where('section_id', $section->id)
                ->delete();

            $sortOrder = 0;
            foreach ($preset['scales'] as $row) {
                GradingScale::create([
                    'school_id' => $schoolId,
                    'section_id' => $section->id,
                    'min' => (string) $row['min'],
                    'max' => (string) $row['max'],
                    'grade' => $row['grade'],
                    'remark' => $row['remark'],
                    'gpa_point' => (float) ($row['gpa_point'] ?? 0),
                    'color' => $row['color'] ?? null,
                    'sort_order' => $sortOrder++,
                ]);
            }
        });

        return response()->json([
            'message' => "Preset '{$preset['name']}' applied to '{$section->name}' successfully.",
        ]);
    }
}
