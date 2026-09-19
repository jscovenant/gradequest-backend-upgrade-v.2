<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'receipt_number',
        'student_id',
        'buyer_name',
        'buyer_phone',
        'subtotal',
        'discount',
        'tax',
        'total_amount',
        'total_cost',
        'amount_tendered',
        'change_due',
        'payment_method',
        'payment_status',
        'served_by_user_id',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_due' => 'decimal:2',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function servedBy()
    {
        return $this->belongsTo(User::class, 'served_by_user_id');
    }

    public function items()
    {
        return $this->hasMany(StoreSaleItem::class, 'sale_id');
    }

    public function getProfitAttribute(): float
    {
        return (float) ($this->total_amount - $this->total_cost);
    }
}
