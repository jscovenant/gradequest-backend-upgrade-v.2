<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Average extends Model
{
    use HasFactory;

    protected $guarded = [];
    // public function user()
    // {
    //     return $this->belongsTo(User::class, 'user_id', 'id');
    // }

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id', 'id');
    }

    public function class()
    {
        return $this->belongsTo(StudentClass::class, 'class_id', 'id');
    }

    public function level()
    {
        return $this->belongsTo(StudentClass::class, 'class_id', 'id');
    }

    public function student()
{
    return $this->belongsTo(User::class, 'user_id');
}


}
