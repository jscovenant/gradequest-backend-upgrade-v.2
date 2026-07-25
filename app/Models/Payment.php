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
        'amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'paystack_response' => 'array',
    ];
  
  public function studentFee()
{
    return $this->belongsTo(StudentFee::class);
}

}
