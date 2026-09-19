<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'category_id',
        'name',
        'item_code',
        'item_type',
        'description',
        'cost_price',
        'selling_price',
        'current_stock',
        'reorder_level',
        'unit',
        'size',
        'class_target',
        'image_url',
        'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'current_stock' => 'integer',
        'reorder_level' => 'integer',
        'is_active' => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function category()
    {
        return $this->belongsTo(StoreCategory::class, 'category_id');
    }

    public function saleItems()
    {
        return $this->hasMany(StoreSaleItem::class, 'store_item_id');
    }

    public function stockLogs()
    {
        return $this->hasMany(StoreStockLog::class, 'store_item_id');
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->current_stock <= $this->reorder_level;
    }
}
