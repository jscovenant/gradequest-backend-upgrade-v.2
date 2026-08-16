<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonScheme extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'topics' => 'array',
        'archived_at' => 'datetime',
    ];
}