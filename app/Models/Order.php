<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number', 'trip_id', 'customer_id', 'created_by',
        'subtotal', 'discount_amount', 'shipping_fee', 'shipping_discount',
        'total_amount', 'deposit_paid', 'payment_status', 'notes',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActiveItemsCountAttribute(): int
    {
        return $this->items()->whereNotIn('status', ['cancelled', 'sold_out'])->sum('quantity');
    }

    public function getRemainingBalanceAttribute(): float
    {
        return $this->total_amount - $this->deposit_paid;
    }

    public function getPaymentStatusBadgeAttribute(): string
    {
        return match($this->payment_status) {
            'paid' => '<span class="badge bg-success">Fully Paid</span>',
            'partial' => '<span class="badge bg-warning text-dark">Partially Paid</span>',
            default => '<span class="badge bg-danger">Unpaid</span>',
        };
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($order) {
            if (!$order->order_number) {
                $order->order_number = 'ORD-' . strtoupper(uniqid());
            }
        });
    }
}
