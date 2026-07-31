<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CbtQuestion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'marks' => 'decimal:2',
        'correct_answer' => 'array',
        'metadata' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(CbtExam::class, 'exam_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CbtExamSection::class, 'section_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CbtQuestionGroup::class, 'question_group_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(CbtQuestionOption::class, 'question_id')->orderBy('sort_order');
    }
}
