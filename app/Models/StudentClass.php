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

    protected $casts = [
        'archived_at' => 'datetime',
        'cbt_enabled' => 'boolean',
    ];

    /**
     * Check if CBT is enabled for this class, checking both the class attribute
     * and the school's fee access policy disabled list.
     */
    public function isCbtEnabled(?array $schoolDisabledIds = null): bool
    {
        if ($schoolDisabledIds !== null && in_array((int) $this->id, $schoolDisabledIds, true)) {
            return false;
        }

        if (array_key_exists('cbt_enabled', $this->attributes)) {
            return (bool) $this->attributes['cbt_enabled'];
        }

        return true;
    }

    
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
