<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class AiCreditTransaction extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected $casts = [
        'credits' => 'integer',
        'metadata' => 'array',
    ];
}
