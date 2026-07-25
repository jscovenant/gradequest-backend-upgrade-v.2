<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserHasPsychomotorDomain extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];



    public function PsychomotorDomain()
    {
        return $this->belongsTo(PsychomotorDomain::class, 'psychomotor_id', 'id');
    }
}
