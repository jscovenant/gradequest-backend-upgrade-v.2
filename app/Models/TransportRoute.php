<?php
namespace App\Models;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
class TransportRoute extends Model
{
    use BelongsToSchool;
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'default_fee' => 'decimal:2'];
    public function stops() { return $this->hasMany(TransportStop::class)->orderBy('sort_order'); }
    public function vehicles() { return $this->hasMany(TransportVehicle::class); }
    public function assignments() { return $this->hasMany(TransportAssignment::class); }
}
