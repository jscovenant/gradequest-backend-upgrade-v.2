<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LessonNote extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'content' => 'array',
        'youtube_videos' => 'array',
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
    ];
}