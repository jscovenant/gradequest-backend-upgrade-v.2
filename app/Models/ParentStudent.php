<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParentStudent extends Model
{
    use HasFactory;

    protected $table = 'parent_students';
    
    protected $guarded = [];
    
    
      /**
     * Each parent-student record belongs to a student.
     */
    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Each parent-student record belongs to a parent.
     */
    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }
}
