<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesAdjustmentItem extends Model
{
    protected $fillable = [
        'sales_adjustment_id', 'order_item_id', 'product_id', 'product_variant_id',
        'quantity', 'unit_price', 'line_total',
    ];

    public function salesAdjustment() { return $this->belongsTo(SalesAdjustment::class); }
    public function orderItem()      { return $this->belongsTo(OrderItem::class); }
    public function product()        { return $this->belongsTo(Product::class); }
    public function variant()        { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
}
