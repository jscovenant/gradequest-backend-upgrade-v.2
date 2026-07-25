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
    
        // ✅ Relationships
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function session()
    {
        return $this->belongsTo(AcademicSession::class);
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
}
