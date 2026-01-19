<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherSubject extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    
     public function teacher() {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function subject() {
        return $this->belongsTo(Subject::class);
    }
}
