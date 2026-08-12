<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class HostelAllocation extends Model
{
    use BelongsToSchool;

    protected $guarded = [];
    protected $casts = ['allocated_at' => 'datetime', 'checked_out_at' => 'datetime'];

    public function hostel() { return $this->belongsTo(Hostel::class); }
    public function room() { return $this->belongsTo(HostelRoom::class, 'hostel_room_id'); }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function session() { return $this->belongsTo(AcademicSession::class, 'session_id'); }
    public function term() { return $this->belongsTo(Term::class); }
    public function events() { return $this->hasMany(HostelAllocationEvent::class); }
}
