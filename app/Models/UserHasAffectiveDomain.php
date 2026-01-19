<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserHasAffectiveDomain extends Model
{
    use HasFactory;
    protected $guarded = [];


    public function affectiveDomain()
    {
        return $this->belongsTo(AffectiveDomain::class, 'affective_id', 'id');
    }
}
