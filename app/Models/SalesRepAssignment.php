<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesRepAssignment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'pipeline_value' => 'decimal:2',
        'expected_students' => 'integer',
        'expected_close_date' => 'date',
        'converted_at' => 'datetime',
        'registration_token_expires_at' => 'datetime',
        'attribution_locked_at' => 'datetime',
    ];

    public function representative(): BelongsTo
    {
        return $this->belongsTo(SalesRepresentative::class, 'sales_representative_id');
    }

    public function demoBooking(): BelongsTo
    {
        return $this->belongsTo(DemoBooking::class);
    }

    public function marketingMaterial(): BelongsTo
    {
        return $this->belongsTo(SalesMarketingMaterial::class, 'marketing_material_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(SchoolSetting::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
