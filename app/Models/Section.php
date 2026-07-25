<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];


    public function level()
    {
        return $this->hasOne(StudentClass::class);
    }

    public function user()
    {
        return $this->hasOne(User::class, 'section_id', 'id');
    }

    public function average()
    {
        return $this->hasOne(Average::class);
    }
    
    //     public function students()
    // {
    //     return $this->hasOne(User::class, 'section_id', 'id');
    // }
    
    public function subjects()
{
    return $this->hasMany(Subject::class, 'section_id',);
}
}
