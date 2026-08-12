<?php
namespace App\Models;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
class TransportAssignment extends Model
{
    use BelongsToSchool;
    protected $guarded = [];
    protected $casts = ['assigned_at' => 'datetime', 'ended_at' => 'datetime'];
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function route() { return $this->belongsTo(TransportRoute::class, 'transport_route_id'); }
    public function stop() { return $this->belongsTo(TransportStop::class, 'transport_stop_id'); }
    public function vehicle() { return $this->belongsTo(TransportVehicle::class, 'transport_vehicle_id'); }
}
