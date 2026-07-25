<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffectiveDomain extends Model
{
   
    use HasFactory;

    protected $guarded = [];

    public function UserHasAffectiveDomain()
    {
        return $this->hasOne(UserHasAffectiveDomain::class);
    }
}
