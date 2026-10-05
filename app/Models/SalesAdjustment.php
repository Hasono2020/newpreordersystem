<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One table, two types:
 *  - Sales Return: goods come back, tied to specific order items. Shrinks
 *    those items (so the order total drops) and refunds money.
 *  - Credit Note: money refunded with NO goods movement and no items. It
 *    only reduces what the customer has PAID; the order total is untouched
 *    because they still keep everything. (Lowering the total too would move
 *    paid and owed together and never clear an overpayment.)
 * Both create a linked, auto-verified refund Payment.
 */
class SalesAdjustment extends Model
{
    protected $fillable = [
        'type', 'adjustment_number', 'order_id', 'trip_id', 'amount', 'reason', 'created_by',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = ['voided_at' => 'datetime'];

    public function order()      { return $this->belongsTo(Order::class); }
    public function trip()       { return $this->belongsTo(Trip::class); }
    public function createdBy()  { return $this->belongsTo(User::class, 'created_by'); }
    public function voidedBy()   { return $this->belongsTo(User::class, 'voided_by'); }
    public function items()      { return $this->hasMany(SalesAdjustmentItem::class); }
    public function payment()    { return $this->hasOne(Payment::class); }

    public function isVoided(): bool { return $this->voided_at !== null; }
    public function isReturn(): bool { return $this->type === 'return'; }
    public function isCreditNote(): bool { return $this->type === 'credit_note'; }

    /**
     * Reserve the next sequential number for this type on this trip —
     * RP/B{batch}/{month}/{seq} or CR/B{batch}/{month}/{seq}, resetting to
     * 000001 per trip. Same atomic, same-transaction locked-increment
     * pattern as Order::reserveOrderNumbers(), just its own counter column
     * so return/credit-note numbering never collides with or depends on
     * order numbering.
     *
     * A trip with no batch_number (predates this feature, or never had one
     * assigned) falls back to a random RP-xxxxxxxx / CR-xxxxxxxx code —
     * returns and credit notes must still be issuable on any trip, old or
     * new, not just ones with a formal batch number.
     */
    public static function reserveNumber(Trip $trip, string $type): string
    {
        $prefix    = $type === 'return' ? 'RP' : 'CR';
        $seqColumn = $type === 'return' ? 'next_return_seq' : 'next_credit_note_seq';

        if (!$trip->batch_number) {
            $attempts = 0;
            do {
                $number = $prefix . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));
                $attempts++;
            } while (static::where('adjustment_number', $number)->exists() && $attempts < 10);
            return $number;
        }

        return \DB::transaction(function () use ($trip, $prefix, $seqColumn) {
            $locked = \DB::table('trips')->where('id', $trip->id)->lockForUpdate()->first();
            $next   = $locked->$seqColumn + 1;
            \DB::table('trips')->where('id', $trip->id)->update([$seqColumn => $next]);

            $month = now()->format('m');
            return $prefix . '/B' . $locked->batch_number . '/' . $month . '/' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }
}