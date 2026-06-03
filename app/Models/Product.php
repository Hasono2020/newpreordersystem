<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['trip_id', 'name', 'sku', 'brand', 'description', 'image', 'price', 'shipping_weight', 'status'];

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getTotalOrderedQuantityAttribute(): int
    {
        return $this->orderItems()->whereNotIn('status', ['cancelled', 'sold_out'])->sum('quantity');
    }
}
