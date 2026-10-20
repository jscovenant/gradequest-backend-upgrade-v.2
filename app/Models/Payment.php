<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use BelongsToSchool;
    use HasFactory;
    
    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'current_paid' => 'float',
        'arrears_paid' => 'float',
        'platform_fee' => 'float',
        'paystack_response' => 'array',
    ];
  
    public function studentFee()
    {
        return $this->belongsTo(StudentFee::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }
}
