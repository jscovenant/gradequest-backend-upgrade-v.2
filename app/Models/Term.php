<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Term extends Model
{
    protected $guarded = [];
 
    use HasFactory;

    
     public function feeTypes()
    {
        return $this->hasMany(FeeType::class, 'term_id');
    }
}
