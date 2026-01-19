<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserHasPsychomotorDomain extends Model
{
    use HasFactory;

    protected $guarded = [];



    public function PsychomotorDomain()
    {
        return $this->belongsTo(PsychomotorDomain::class, 'psychomotor_id', 'id');
    }
}
