<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number', 'trip_id', 'customer_id', 'shipping_area_id', 'created_by', 'cs_agent_id',
        'subtotal', 'discount_amount', 'shipping_fee', 'shipping_discount',
        'shipping_weight_gram', 'shipping_kg_charged',
        'total_amount', 'deposit_paid', 'payment_status', 'notes', 'ordered_at',
        'invoice_printed_at', 'invoice_printed_by',
    ];

    protected $casts = ['ordered_at' => 'datetime', 'invoice_printed_at' => 'datetime'];

    public function trip()        { return $this->belongsTo(Trip::class); }
    public function customer()    { return $this->belongsTo(Customer::class); }
    public function shippingArea(){ return $this->belongsTo(ShippingArea::class); }
    public function items()       { return $this->hasMany(OrderItem::class); }
    public function payments()    { return $this->hasMany(Payment::class); }
    public function createdBy()   { return $this->belongsTo(User::class, 'created_by'); }
    public function csAgent()     { return $this->belongsTo(CsAgent::class); }
    public function invoicePrintedBy() { return $this->belongsTo(User::class, 'invoice_printed_by'); }

    public function getActiveItemsCountAttribute(): int
    {
        // Use the loaded items collection if available to avoid an extra query (N+1)
        if ($this->relationLoaded('items')) {
            return $this->items
                ->whereNotIn('status', ['cancelled', 'sold_out'])
                ->sum('quantity');
        }
        return $this->items()->whereNotIn('status', ['cancelled', 'sold_out'])->sum('quantity');
    }

    public function getRemainingBalanceAttribute(): float
    {
        return $this->total_amount - $this->deposit_paid;
    }

    /**
     * Fix #2: single source of truth for payment status recalculation.
     * Previously duplicated identically in OrderController, PaymentController,
     * and CreditReallocationService — any bug fix had to be applied in 3 places.
     * All three now delegate here instead of maintaining their own copy.
     */
    public function recalcPaymentStatus(): void
    {
        $payments = $this->payments()->whereNull('voided_at')->get();
        $paid = $payments->where('type', '!=', 'refund')->sum('amount')
              - $payments->where('type', 'refund')->sum('amount');
        $status = $paid <= 0 ? 'unpaid'
            : ($paid >= $this->total_amount ? 'paid' : 'partial');
        // Auto-confirm pending items when fully paid.
        // Do NOT revert confirmed items when a payment is voided (intentional).
        if ($status === 'paid') {
            $this->items()->where('status', 'pending')->update(['status' => 'confirmed']);
        }
        $this->update(['deposit_paid' => max(0, $paid), 'payment_status' => $status]);
    }

    public function getPaymentStatusBadgeAttribute(): string
    {
        return match($this->payment_status) {
            'paid'    => '<span class="badge bg-success">Fully Paid</span>',
            'partial' => '<span class="badge bg-warning text-dark">Partially Paid</span>',
            default   => '<span class="badge bg-danger">Unpaid</span>',
        };
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($order) {
            if (!$order->order_number) {
                do {
                    $number = 'ORD-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));
                } while (static::where('order_number', $number)->exists());
                $order->order_number = $number;
            }
        });
    }
}