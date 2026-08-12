<?php
namespace App\Models;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
class HostelAllocationEvent extends Model
{
    use BelongsToSchool;
    protected $guarded = [];
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function fromHostel() { return $this->belongsTo(Hostel::class, 'from_hostel_id'); }
    public function fromRoom() { return $this->belongsTo(HostelRoom::class, 'from_room_id'); }
    public function toHostel() { return $this->belongsTo(Hostel::class, 'to_hostel_id'); }
    public function toRoom() { return $this->belongsTo(HostelRoom::class, 'to_room_id'); }
    public function actor() { return $this->belongsTo(User::class, 'performed_by'); }
}
