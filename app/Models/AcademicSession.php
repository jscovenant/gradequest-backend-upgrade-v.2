<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcademicSession extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'archived_at' => 'datetime',
        'is_current' => 'boolean',
    ];
}
