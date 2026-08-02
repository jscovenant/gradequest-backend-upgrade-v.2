<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CbtExam extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'settings' => 'array',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'show_result_after_submit' => 'boolean',
        'access_code_required' => 'boolean',
        'calculator_enabled' => 'boolean',
        'total_marks' => 'decimal:2',
        'pass_mark' => 'decimal:2',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(StudentClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CbtExamSection::class, 'exam_id')->orderBy('sort_order');
    }

    public function questionGroups(): HasMany
    {
        return $this->hasMany(CbtQuestionGroup::class, 'exam_id')->orderBy('sort_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CbtQuestion::class, 'exam_id')->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CbtAttempt::class, 'exam_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(CbtExamSchedule::class, 'exam_id')->orderBy('exam_date')->orderBy('starts_at');
    }
}
