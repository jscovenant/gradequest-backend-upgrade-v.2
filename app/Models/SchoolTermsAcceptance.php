<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolTermsAcceptance extends Model
{
    public const CURRENT_VERSION = '2026-08-09';

    protected $guarded = [];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];
}
