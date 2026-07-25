<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolBillingPeriod extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'academic_start_date' => 'date',
        'billing_started_at' => 'datetime',
        'billing_grace_ends_at' => 'datetime',
        'term_activated_at' => 'datetime',
        'first_protected_activity_at' => 'datetime',
        'locked_at' => 'datetime',
        'meta' => 'array',
        'suspicious_flags' => 'array',
        'flagged_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function session()
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function term()
    {
        return $this->belongsTo(Term::class, 'term_id');
    }
}
