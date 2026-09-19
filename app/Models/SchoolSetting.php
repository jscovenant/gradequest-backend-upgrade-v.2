<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolSetting extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $appends = ['name'];

    public function getNameAttribute(): ?string
    {
        return $this->school_name ?? null;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'school_id', 'id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'school_id');
    }

    public function websiteSetting()
    {
        return $this->hasOne(SchoolWebsiteSetting::class, 'school_id');
    }

    public function admissionSetting()
    {
        return $this->hasOne(SchoolAdmissionSetting::class, 'school_id');
    }

    public function admissionApplications()
    {
        return $this->hasMany(SchoolAdmissionApplication::class, 'school_id');
    }

    public function domainOrders()
    {
        return $this->hasMany(SchoolDomainOrder::class, 'school_id');
    }

    public function customDomains()
    {
        return $this->hasMany(SchoolDomain::class, 'school_id');
    }
}
