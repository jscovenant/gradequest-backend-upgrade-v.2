<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreSaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'sale_id',
        'store_item_id',
        'item_name',
        'item_code',
        'quantity',
        'unit_cost',
        'unit_price',
        'total_price',
        'profit',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'profit' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public function sale()
    {
        return $this->belongsTo(StoreSale::class, 'sale_id');
    }

    public function storeItem()
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }
}
