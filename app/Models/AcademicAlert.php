<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcademicAlert extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];


     protected $casts = [
        'context_json' => 'array',
        'reviewed_at' => 'datetime',
    ];
}
