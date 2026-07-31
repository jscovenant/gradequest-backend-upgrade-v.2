<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CbtExamSection extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'default_marks' => 'decimal:2',
        'shuffle_questions' => 'boolean',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(CbtExam::class, 'exam_id');
    }

    public function questionGroups(): HasMany
    {
        return $this->hasMany(CbtQuestionGroup::class, 'section_id')->orderBy('sort_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CbtQuestion::class, 'section_id')->orderBy('sort_order');
    }
}
