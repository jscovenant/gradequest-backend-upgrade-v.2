<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'archived_at' => 'datetime',
    ];



    public function user()
    {

        return $this->hasOne(User::class, 'department_id', 'id');
    }

    public function subjects()
{
    return $this->hasMany(Subject::class);
}
}
