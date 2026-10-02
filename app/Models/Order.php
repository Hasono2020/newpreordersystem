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
        'invoice_printed_at', 'invoice_printed_by', 'source',
    ];

    protected $casts = ['ordered_at' => 'datetime', 'invoice_printed_at' => 'datetime'];

    public function trip()        { return $this->belongsTo(Trip::class); }
    public function customer()    { return $this->belongsTo(Customer::class); }
    public function shippingArea(){ return $this->belongsTo(ShippingArea::class); }
    public function items()       { return $this->hasMany(OrderItem::class); }
    public function payments()    { return $this->hasMany(Payment::class); }
    public function salesAdjustments() { return $this->hasMany(SalesAdjustment::class); }

    /**
     * True once every item on this order has been reduced to zero quantity
     * by one or more Sales Returns — i.e. the whole order was returned, not
     * just part of it. Requires the items relation to already be loaded
     * (callers on a list page should eager-load 'items').
     */
    public function isFullyReturned(): bool
    {
        if ($this->items->isEmpty()) return false;
        return $this->items->sum('quantity') <= 0;
    }
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
     * Fix #1: single source of truth for payment recalculation.
     * Previously duplicated in OrderController, PaymentController,
     * and CreditReallocationService — any bug fix had to be applied in 3 places.
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
                $trip = $order->trip_id ? \App\Models\Trip::find($order->trip_id) : null;
                $order->order_number = $trip
                    ? static::reserveOrderNumbers($trip, 1)[0]
                    : static::randomOrderNumber();
            }
        });
    }

    /**
     * Reserve $count sequential order numbers for a trip in one atomic
     * step — used both for a single order (the creating() hook above) and
     * for a whole Excel-import batch, which needs a contiguous block
     * without a separate locked DB round-trip per row.
     *
     * Only trips with a batch_number get the new ORD/B{batch}/{month}/{seq}
     * format — a trip created before this feature (batch_number null) keeps
     * issuing the old random ORD-xxxxxxxx codes, so existing trips and
     * their orders are completely unaffected.
     *
     * The lock is scoped to this one trip row (not the whole table), so
     * concurrent order creation on DIFFERENT trips never blocks each other
     * — only two people creating orders on the SAME trip at the same
     * instant briefly serialize, which is exactly what "no two orders ever
     * get the same sequence number" requires.
     */
    public static function reserveOrderNumbers(\App\Models\Trip $trip, int $count = 1): array
    {
        if (!$trip->batch_number) {
            return array_map(fn () => static::randomOrderNumber(), range(1, $count));
        }

        return \DB::transaction(function () use ($trip, $count) {
            $locked = \DB::table('trips')->where('id', $trip->id)->lockForUpdate()->first();
            $start  = $locked->next_order_seq + 1;

            \DB::table('trips')->where('id', $trip->id)->update([
                'next_order_seq' => $locked->next_order_seq + $count,
            ]);

            $month = now()->format('m');
            $numbers = [];
            for ($i = 0; $i < $count; $i++) {
                $seq = $start + $i;
                $numbers[] = 'ORD/B' . $locked->batch_number . '/' . $month . '/' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
            }
            return $numbers;
        });
    }

    private static function randomOrderNumber(): string
    {
        $attempts = 0;
        do {
            $number = 'ORD-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));
            $attempts++;
        } while (static::where('order_number', $number)->exists() && $attempts < 10);
        return $number;
    }
}