<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradingScale extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];
    protected $table = 'grading_scales';

    protected $casts = [
        'min' => 'float',
        'max' => 'float',
        'gpa_point' => 'float',
        'sort_order' => 'integer',
    ];

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id', 'id');
    }
}
