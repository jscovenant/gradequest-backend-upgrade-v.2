<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function subjectenroll()
    {
        return $this->hasOne(SubjectEnroll::class);
    }

    public function firstterm()
    {
        return $this->hasMany(FirstTermResult::class);
    }

    public function secondterm()
    {
        return $this->hasMany(SecondTermResult::class);
    }

    public function thirdterm()
    {
        return $this->hasMany(ThirdTermResult::class);
    }

    public function level()
    {
        return $this->belongsTo(Level::class, 'class_id', 'id');
    }

    public function department()
{
    return $this->belongsTo(Department::class);
}

public function section()
{
    return $this->belongsTo(Section::class, 'section_id');
}

}
