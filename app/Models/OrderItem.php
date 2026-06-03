<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'product_id', 'product_variant_id', 'quantity', 'unit_price', 'line_total', 'status', 'notes'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'confirmed' => '<span class="badge bg-primary">Confirmed</span>',
            'purchased' => '<span class="badge bg-info">Purchased</span>',
            'arrived' => '<span class="badge bg-success">Arrived</span>',
            'sold_out' => '<span class="badge bg-danger">Sold Out</span>',
            'cancelled' => '<span class="badge bg-secondary">Cancelled</span>',
            default => '<span class="badge bg-warning text-dark">Pending</span>',
        };
    }
}
