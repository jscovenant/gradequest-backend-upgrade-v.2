<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentClass extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];
    protected $table = 'student_classes';

    
    public function user()
    {

        return $this->hasMany(User::class, 'level_id', 'id');
    }
    
    

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id', 'id');
    }

    public function teacherenrollment()
    {
        return $this->hasOne(TeacherEnrollment::class);
    }

    public function average()
    {
        return $this->hasOne(Average::class);
    }


    public function subject()
    {
        return $this->hasOne(Subject::class);
    }
    
       public function students()
    {
        return $this->hasMany(User::class, 'level_id');
    }
    
    
    public function timetables()
    {
        return $this->hasMany(Timetable::class, 'class_id');
    }



    
}
