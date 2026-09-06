<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradiosEduInvoicePayment extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $table = 'gradequest_invoice_payments';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'paystack_response' => 'array',
        'paid_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(GradiosEduTermInvoice::class, 'invoice_id');
    }
}
