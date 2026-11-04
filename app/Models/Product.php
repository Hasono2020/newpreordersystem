<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id', 'sku', 'product_code', 'brand', 'supplier_id',
        'notes', 'image', 'price', 'shipping_weight',
        'weight_gram', 'excluded_from_promo', 'status',
    ];

    protected $casts = [
        'excluded_from_promo' => 'boolean',
    ];

    public function trip()      { return $this->belongsTo(Trip::class); }
    public function supplier()  { return $this->belongsTo(Supplier::class); }
    public function variants()  { return $this->hasMany(ProductVariant::class); }
    public function orderItems(){ return $this->hasMany(OrderItem::class); }

    /** Every status a product can have. "deactivated" = off sale (e.g. sold out): can't be picked for a new order. */
    public const STATUSES = ['active', 'closed', 'arrived', 'deactivated'];

    public function isDeactivated(): bool
    {
        return $this->status === 'deactivated';
    }

    /** Products that can still be picked when creating an order or adding an item. */
    public function scopeOrderable(Builder $query): Builder
    {
        return $query->where('products.status', '!=', 'deactivated');
    }

    public function getTotalOrderedQuantityAttribute(): int
    {
        return $this->orderItems()->whereNotIn('status', ['cancelled', 'sold_out'])->sum('quantity');
    }

    /** Code prefix before underscore, e.g. "NZ" from "NZ_01" */
    public function getCodePrefixAttribute(): ?string
    {
        if (!$this->product_code) return null;
        return strtoupper(explode('_', $this->product_code)[0]);
    }
}