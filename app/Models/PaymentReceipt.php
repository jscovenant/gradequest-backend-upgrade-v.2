<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    
    
        // Accessor for full URL
    public function getReceiptUrlAttribute()
    {
        return $this->receipt_path ? asset("storage/{$this->receipt_path}") : null;
    }
    
    
    
public function student()
{
    return $this->belongsTo(User::class, 'student_id', 'id');
}

}
