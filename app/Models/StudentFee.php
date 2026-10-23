<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class StudentFee extends Model
{
    use BelongsToSchool;
    use HasFactory;
    
    protected $guarded = [];

    protected $casts = [
        'gross_amount' => 'float',
        'discount_amount' => 'float',
        'discount_rate' => 'float',
        'net_amount' => 'float',
        'total_amount' => 'float',
        'amount_paid' => 'float',
        'balance' => 'float',
    ];
    
    // ✅ Relationships
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function session()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function academicSession()
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

   public function feeType()
{
    return $this->belongsTo(FeeType::class, 'fee_type_id');
}

    public function payments()
    {
        return $this->hasMany(Payment::class, 'student_fee_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function discountApprover()
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }
}
