<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentResultV2 extends Model
{
    use HasFactory;



     protected $table = 'student_results_v2';
  protected $casts = [
    'meta_json' => 'array',
  ];

  public function subjectResults()
  {
    return $this->hasMany(SubjectResultV2::class, 'student_result_id');
  }

  public function student()
  {
    return $this->belongsTo(User::class, 'user_id');
  }

}
