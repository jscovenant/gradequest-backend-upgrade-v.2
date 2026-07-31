<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CbtQuestionGroup extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(CbtExam::class, 'exam_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CbtExamSection::class, 'section_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CbtQuestion::class, 'question_group_id')->orderBy('sort_order');
    }
}
