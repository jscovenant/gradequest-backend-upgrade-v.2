<?php

namespace App\Http\Requests\Result;

use Illuminate\Foundation\Http\FormRequest;

class GetReportCardRequest extends FormRequest
{
   public function authorize(): bool { return true; }

  public function rules(): array
  {
    return [
      'student_id' => ['required','integer'],
      'school_id'  => ['required','integer'],
      'class_id'   => ['required','integer'],
      'term'       => ['required','string','max:255'],
      'session'    => ['required','string','max:255'],
    ];
  }
}
