<?php

namespace App\Models\Concerns;

use App\Support\CurrentSchool;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope('school', function (Builder $builder) {
            $schoolId = app(CurrentSchool::class)->id();

            if ($schoolId) {
                $builder->where($builder->getModel()->getTable() . '.school_id', $schoolId);
            }
        });

        static::creating(function ($model) {
            $schoolId = app(CurrentSchool::class)->id();

            if ($schoolId && empty($model->school_id)) {
                $model->school_id = $schoolId;
            }
        });
    }

    public function scopeForSchool(Builder $query, int $schoolId): Builder
    {
        return $query->withoutGlobalScope('school')->where($this->getTable() . '.school_id', $schoolId);
    }
}
