<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'address', 'type', 'notes', 'default_shipping_area_id'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function defaultShippingArea()
    {
        return $this->belongsTo(ShippingArea::class, 'default_shipping_area_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'reseller' => 'Reseller',
            'selected_customer' => 'Selected Customer',
            default => 'Customer',
        };
    }
}
