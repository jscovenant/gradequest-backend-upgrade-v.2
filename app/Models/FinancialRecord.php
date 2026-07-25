<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialRecord extends Model
{
    use BelongsToSchool;
    use HasFactory;

        protected $guarded = [];

        protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function school()
    {
        return $this->belongsTo(User::class, 'school_id', 'id');
    }

    public function category()
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id', 'id');
    }
}
