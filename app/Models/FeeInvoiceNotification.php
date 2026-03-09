<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeInvoiceNotification extends Model
{
    use HasFactory;

    protected $guarded = [];

       protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }
}
