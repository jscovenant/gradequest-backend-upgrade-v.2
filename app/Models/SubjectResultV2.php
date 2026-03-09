<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubjectResultV2 extends Model
{
    use HasFactory;


    protected $table = 'subject_results_v2';
  protected $casts = [
    'carry_over_json' => 'array',
  ];

  public function studentResult()
  {
    return $this->belongsTo(StudentResultV2::class, 'student_result_id');
  }

  public function subject()
  {
    return $this->belongsTo(Subject::class, 'subject_id');
  }
}
