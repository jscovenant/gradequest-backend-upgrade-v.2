<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PsychomotorDomain extends Model
{
 
    use HasFactory;
    protected $guarded = [];

    public function UserHasPsychomotorDomain()
    {
        return $this->hasOne(UserHasPsychomotorDomain::class);
    }
}
