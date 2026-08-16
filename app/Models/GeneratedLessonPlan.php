<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeneratedLessonPlan extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'plan' => 'array',
        'archived_at' => 'datetime',
    ];
}