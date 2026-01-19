<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    use HasFactory;

    protected $guarded = [];


    public function level()
    {
        return $this->hasOne(Level::class);
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function average()
    {
        return $this->hasOne(Average::class);
    }
    
        public function students()
    {
        return $this->hasMany(User::class, 'section_id');
    }
    
    public function subjects()
{
    return $this->hasMany(Subject::class, 'section_id');
}
}
