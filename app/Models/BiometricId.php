<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BiometricId extends Model
{
    use HasFactory;
    
    protected $guarded = [];
    
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function qrCodes()
    {
        return $this->hasMany(QrCode::class);
    }
    
      public function teacher()
    {
        return $this->belongsTo(User::class, "user_id");
    }

}
