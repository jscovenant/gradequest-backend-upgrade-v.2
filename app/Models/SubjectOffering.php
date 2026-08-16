<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubjectOffering extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_compulsory' => 'boolean',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}