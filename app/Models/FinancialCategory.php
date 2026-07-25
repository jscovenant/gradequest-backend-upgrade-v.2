<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialCategory extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];

    public function records()
    {
        return $this->hasMany(FinancialRecord::class, 'category_id', 'id');
    }


}
