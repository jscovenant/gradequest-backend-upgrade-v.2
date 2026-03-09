<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeInvoice extends Model
{
    use HasFactory;

   

protected $casts = [
    'first_reminded_at' => 'datetime',
    'last_reminded_at'  => 'datetime',
    'next_reminder_at'  => 'datetime',
    'paid_at'           => 'datetime',
];
}
