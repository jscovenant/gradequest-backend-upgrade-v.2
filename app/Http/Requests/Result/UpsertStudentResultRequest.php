<?php

namespace App\Http\Requests\Result;

use Illuminate\Foundation\Http\FormRequest;


use Illuminate\Validation\Rule;

class UpsertStudentResultRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'rollno' => ['nullable','string','max:255'],
            'department' => ['nullable','string','max:255'],
            'section_id' => ['nullable','integer'],

            'summary' => ['required','array'],
            'summary.total_grade' => ['nullable','string','max:255'],
            'summary.principal_comment' => ['nullable','string','max:255'],
            'summary.class_teacher_comment' => ['nullable','string','max:255'],
            'summary.general_remark' => ['nullable','string','max:255'],
            'summary.total_average' => ['nullable','string','max:255'],
            'summary.position' => ['nullable','string','max:20'],
            'summary.class_teacher' => ['nullable','string','max:255'],
            'summary.class_size' => ['nullable','string','max:255'],

            'summary.meta' => ['nullable','array'],
            'summary.meta.resumption_date' => ['nullable','string','max:255'],
            'summary.meta.school_open' => ['nullable','string','max:255'],
            'summary.meta.school_close' => ['nullable','string','max:255'],
            'summary.meta.no_present' => ['nullable','string','max:255'],
            'summary.meta.no_absent' => ['nullable','string','max:255'],

            'results' => ['required','array','min:1'],
            'results.*.subject_id' => ['required','integer'],
            'results.*.ca' => ['nullable'], // array or string (you handle it)
            'results.*.exam' => ['nullable','numeric'],
            'results.*.total' => ['nullable','numeric'],
            'results.*.grade' => ['nullable','string','max:255'],
            'results.*.remark' => ['nullable','string','max:255'],
            'results.*.comment' => ['nullable','string','max:255'],
            'results.*.signature' => ['nullable','string','max:255'],

            // carry over payload from frontend
            'results.*.carry_over' => ['nullable','array'],
            'results.*.carry_over.enabled' => ['nullable','boolean'],

            // dynamic maps: {"First Term": 65, ...}
            'results.*.carry_over.terms' => ['nullable','array'],
            'results.*.carry_over.terms.*' => ['nullable','numeric'],

            // {"Third Term": 68}
            'results.*.carry_over.current_term' => ['nullable','array'],
            'results.*.carry_over.current_term.*' => ['nullable','numeric'],

            'results.*.carry_over.cumulative_total' => ['nullable','numeric'],
            'results.*.carry_over.cumulative_average' => ['nullable','numeric'],
        ];
    }
}

