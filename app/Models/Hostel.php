<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Hostel extends Model
{
    use BelongsToSchool;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function rooms() { return $this->hasMany(HostelRoom::class); }
    public function allocations() { return $this->hasMany(HostelAllocation::class); }
}
