<?php

namespace App\Http\Requests\Result;

use Illuminate\Foundation\Http\FormRequest;

class ResolveBatchRequest extends FormRequest
{
 
      public function authorize(): bool { return true; }

  public function rules(): array
  {
    return [
      'class_id'  => ['required','integer'],
      'term'      => ['required','string','max:255'],
      'session'   => ['required','string','max:255'],
    ];
  }
}
