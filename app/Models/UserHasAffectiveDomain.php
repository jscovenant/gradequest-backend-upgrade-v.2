<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserHasAffectiveDomain extends Model
{
    use BelongsToSchool;
    use HasFactory;
    protected $guarded = [];


    public function affectiveDomain()
    {
        return $this->belongsTo(AffectiveDomain::class, 'affective_id', 'id');
    }
}
