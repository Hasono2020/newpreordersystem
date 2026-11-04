<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'color', 'size', 'price_adjustment', 'supplier_stock', 'allocated_qty', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** Variants that can still be picked for a new order (not deactivated). */
    public function scopeOrderable(Builder $query): Builder
    {
        return $query->where('product_variants.is_active', true);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getLabelAttribute(): string
    {
        $parts = array_filter([$this->color, $this->size]);
        return implode(' / ', $parts) ?: 'Default';
    }

    public function getRemainingStockAttribute(): int
    {
        return $this->supplier_stock - $this->allocated_qty;
    }

    public function getFinalPriceAttribute(): float
    {
        return $this->product->price + $this->price_adjustment;
    }
}