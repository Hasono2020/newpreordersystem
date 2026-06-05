<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'destination', 'trip_date', 'order_deadline', 'status', 'notes', 'created_by'];

    protected $casts = [
        'trip_date' => 'date',
        'order_deadline' => 'date',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function promoRules()
    {
        return $this->hasMany(PromoRule::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'open'         => '<span class="badge bg-success">Open</span>',
            'order_closed' => '<span class="badge bg-warning text-dark">Order Closed</span>',
            'purchasing'   => '<span class="badge" style="background:#7c3aed">Purchasing</span>',
            'arrived'      => '<span class="badge bg-info">Arrived</span>',
            'closed'       => '<span class="badge bg-secondary">Closed</span>',
            default        => '<span class="badge bg-light text-dark">'.$this->status.'</span>',
        };
    }
}
