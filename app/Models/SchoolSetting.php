<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolSetting extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $appends = ['name'];

    public function getNameAttribute(): ?string
    {
        return $this->school_name ?? null;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'school_id', 'id');
    }
}
