<?php
namespace App\Models;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
class TransportVehicle extends Model
{
    use BelongsToSchool;
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'capacity' => 'integer'];
    public function route() { return $this->belongsTo(TransportRoute::class, 'transport_route_id'); }
    public function assignments() { return $this->hasMany(TransportAssignment::class); }
}
