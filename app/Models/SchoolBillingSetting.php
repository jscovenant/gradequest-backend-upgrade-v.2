<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolBillingSetting extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'block_results_when_unpaid' => 'boolean',
        'switched_at' => 'datetime',
        'platform_fee_per_student' => 'decimal:2',
    ];
}
