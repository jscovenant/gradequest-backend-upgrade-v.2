<?php

namespace App\Http\Requests\Result;

use Illuminate\Foundation\Http\FormRequest;

class GetReportCardRequest extends FormRequest
{
   public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (!$this->has('school_id') || empty($this->school_id)) {
            $student = \App\Models\User::find($this->student_id);
            $schoolId = $student?->school_id ?? auth()->user()?->school_id;
            if ($schoolId) {
                $this->merge(['school_id' => $schoolId]);
            }
        }
    }

  public function rules(): array
  {
    return [
      'student_id' => ['required','integer'],
      'school_id'  => ['nullable','integer'],
      'class_id'   => ['required','integer'],
      'term'       => ['required','string','max:255'],
      'session'    => ['required','string','max:255'],
    ];
  }
}
