<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeType extends Model
{
    use BelongsToSchool;
    use HasFactory;
    
    protected $guarded = [];
    
  public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function session()
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }
    
    
       public function term()
    {
        return $this->belongsTo(Term::class, 'term_id');
    }
    
   public function studentFees()
{
    return $this->hasMany(StudentFee::class, 'fee_type_id');
}

}

