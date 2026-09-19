<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolAdmissionApplication extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'dob' => 'date',
        'enrolled_at' => 'datetime',
        'documents' => 'array',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function level()
    {
        return $this->belongsTo(StudentClass::class, 'level_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function session()
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function payment()
    {
        return $this->hasOne(SchoolAdmissionPayment::class, 'application_id');
    }

    public function enrolledUser()
    {
        return $this->belongsTo(User::class, 'enrolled_user_id');
    }

    public function enrolledParent()
    {
        return $this->belongsTo(User::class, 'enrolled_parent_id');
    }
}
