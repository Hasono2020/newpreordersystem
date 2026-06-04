<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = ['po_number', 'trip_id', 'supplier_id', 'total_amount', 'status', 'purchased_at', 'notes', 'created_by'];

    protected $casts = [
        'purchased_at' => 'date',
    ];

    public function trip()      { return $this->belongsTo(Trip::class); }
    public function supplier()  { return $this->belongsTo(Supplier::class); }
    public function items()     { return $this->hasMany(PurchaseOrderItem::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($po) {
            if (!$po->po_number) {
                $po->po_number = 'PO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            }
        });
    }
}
