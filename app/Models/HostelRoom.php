<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class HostelRoom extends Model
{
    use BelongsToSchool;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'capacity' => 'integer'];

    public function hostel() { return $this->belongsTo(Hostel::class); }
    public function allocations() { return $this->hasMany(HostelAllocation::class); }
}
