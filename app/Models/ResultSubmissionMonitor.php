<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultSubmissionMonitor extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];


      protected $casts = [
        'submission_deadline' => 'date',
        'last_teacher_reminder_sent_at' => 'datetime',
        'last_admin_reminder_sent_at' => 'datetime',
        'last_scanned_at' => 'datetime',
        'meta_json' => 'array',
    ];
}
