<?php
namespace App\Models;
use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
class TransportStop extends Model
{
    use BelongsToSchool;
    protected $guarded = [];
    protected $casts = ['fee' => 'decimal:2'];
    public function route() { return $this->belongsTo(TransportRoute::class, 'transport_route_id'); }
}
