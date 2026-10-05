<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * After a price sync (product price change or shipping rate change)
 * updates order totals, a customer can end up with one order overpaid
 * and another underpaid within the same trip — the money was recorded
 * per-order, but the totals shifted per-order too.
 *
 * Only VERIFIED money is ever moved — an unverified deposit (a customer
 * saying they transferred) stays where it is until someone confirms it.
 *
 * This service automatically reallocates the overpaid amount to cover
 * the underpaid order(s), oldest shortfall first (matching the FIFO
 * convention already used elsewhere for payment allocation). It never
 * silently edits deposit_paid — every reallocation leaves a full,
 * visible trail in Payment History: a 'refund'-type deduction on the
 * overpaid order and a 'partial'-type addition on the underpaid one,
 * both cross-referencing each other, so a later "why did this change"
 * question always has an answer.
 */
class CreditReallocationService
{
    /**
     * Keep a customer's cross-order transfers in line with the money they
     * actually hold RIGHT NOW. Call this after anything that changes what an
     * order has paid or owes: a payment recorded or voided, an item added or
     * removed, a Sales Return or Credit Note, a price sync.
     *
     * Two steps, in this order:
     *  1. Undo any earlier transfer that is no longer backed by real money
     *     (e.g. the deposit it was drawn from has since been voided) — otherwise
     *     the receiving order would keep showing "paid" with nothing behind it.
     *  2. Move spare credit onto any order that is short (reallocate()).
     *
     * Idempotent: once everything is consistent, calling it again changes
     * nothing, so it is safe to call from several places in one request.
     */
    public function reconcile(int $customerId, int $tripId): void
    {
        $this->unwindUnsupportedTransfers($customerId, $tripId);
        $this->reallocate($customerId, $tripId);
    }

    /**
     * An order can only have sent out as much credit as its OWN money exceeds
     * its own total by. "Own money" = every non-voided payment on it except
     * the transfer entries themselves (deposits, partials, and refunds from
     * Credit Notes / Sales Returns). If more has been sent out than that, the
     * newest transfers are reversed — both sides, voided with a reason, never
     * deleted — and reallocate() then re-moves whatever is still supportable.
     */
    private function unwindUnsupportedTransfers(int $customerId, int $tripId): void
    {
        $orders = Order::with('payments')
            ->where('customer_id', $customerId)
            ->where('trip_id', $tripId)
            ->get();

        $isTransfer = fn ($p) => $p->method === 'reallocation';
        $reversed   = [];

        DB::transaction(function () use ($orders, $isTransfer, &$reversed) {
            foreach ($orders as $order) {
                $active   = $order->payments->filter(fn ($p) => $p->voided_at === null);
                $outgoing = $active->filter(fn ($p) => $isTransfer($p) && $p->type === 'refund')
                    ->sortByDesc('id')->values();
                if ($outgoing->isEmpty()) continue;

                // Only VERIFIED own money supports a transfer: if the deposit it
                // came from is disputed or still unverified, the move is undone.
                $ownMoney = $active->reject($isTransfer)->sum(function ($p) {
                    if ($p->type === 'refund') return -(float) $p->amount;
                    return $p->verification_status === 'verified' ? (float) $p->amount : 0.0;
                });
                $supported = max(0.0, $ownMoney - (float) $order->total_amount);
                $outTotal  = (float) $outgoing->sum(fn ($p) => (float) $p->amount);

                foreach ($outgoing as $out) {
                    if ($outTotal <= $supported + 0.001) break;

                    $in = Payment::where('batch_id', $out->batch_id)
                        ->where('method', 'reallocation')
                        ->where('type', 'partial')
                        ->whereNull('voided_at')
                        ->first();

                    foreach (array_filter([$out, $in]) as $row) {
                        $row->update([
                            'voided_at'   => now(),
                            'voided_by'   => Auth::id(),
                            'void_reason' => 'Reversed — the money backing this transfer was changed or removed',
                        ]);
                    }

                    $outTotal -= (float) $out->amount;
                    $reversed[] = [
                        'from_id' => $order->id, 'to_id' => $in?->order_id,
                        'from'    => $order->order_number, 'to' => $in?->order?->order_number,
                        'amount'  => (float) $out->amount,
                    ];
                }
            }
        });

        if (empty($reversed)) return;

        Order::whereIn('id', collect($reversed)->flatMap(fn ($r) => [$r['from_id'], $r['to_id']])->filter()->unique())
            ->get()->each(fn ($o) => $o->recalcPaymentStatus());

        $summary = collect($reversed)
            ->map(fn ($r) => 'Rp' . number_format($r['amount'], 0, ',', '.') . " from {$r['from']} to " . ($r['to'] ?? 'another order'))
            ->implode('; ');
        ActivityLog::record('payment.reallocation_reversed', "Reversed auto-reallocation no longer backed by a payment: {$summary}", 'customer', $customerId);
    }

    /**
     * Reallocates credit for one customer within one trip. Call this
     * after any bulk price sync that could create an overpay/underpay
     * split for that customer. Safe to call even when there's nothing
     * to reallocate — it's a no-op in that case.
     */
    public function reallocate(int $customerId, int $tripId): void
    {
        $orders = Order::where('customer_id', $customerId)
            ->where('trip_id', $tripId)
            ->get();

        // Only VERIFIED surplus can be moved. Every transfer this creates is
        // marked verified, so drawing on money nobody has confirmed arrived
        // would turn it into "verified" credit that can then be refunded.
        $overpaid  = $orders->filter(fn($o) => $this->spare($o) > 0.001)->values();
        $underpaid = $orders->filter(fn($o) => (float) $o->deposit_paid < (float) $o->total_amount)
            ->sortBy('ordered_at')->values(); // FIFO — oldest shortfall covered first

        if ($overpaid->isEmpty() || $underpaid->isEmpty()) {
            return;
        }

        $transfers = [];

        DB::transaction(function () use ($overpaid, $underpaid, &$transfers) {
            foreach ($underpaid as $shortOrder) {
                $needed = (float) $shortOrder->total_amount - (float) $shortOrder->deposit_paid;
                if ($needed <= 0) continue;

                foreach ($overpaid as $creditOrder) {
                    $creditOrder->refresh();
                    $available = $this->spare($creditOrder);
                    if ($available <= 0) continue;

                    $amount = min($needed, $available);
                    if ($amount <= 0) continue;

                    $batchId    = (string) Str::uuid();
                    $recordedBy = Auth::id() ?? $creditOrder->created_by;
                    // These are internal ledger entries, not external bank
                    // transfers — there's nothing for staff to verify against
                    // a bank statement, so mark them verified immediately
                    // rather than leaving them sitting in the unverified
                    // queue looking like a real transaction that needs review.
                    $verification = [
                        'verification_status' => 'verified',
                        'verified_by'          => $recordedBy,
                        'verified_at'          => now(),
                    ];

                    $creditOrder->payments()->create(array_merge($verification, [
                        'batch_id'    => $batchId,
                        'amount'      => $amount,
                        'type'        => 'refund',
                        'method'      => 'reallocation',
                        'reference'   => "Reallocated to {$shortOrder->order_number}",
                        'paid_at'     => now(),
                        'notes'       => "Auto-reallocated overpayment to cover balance on {$shortOrder->order_number}",
                        'recorded_by' => $recordedBy,
                    ]));
                    $shortOrder->payments()->create(array_merge($verification, [
                        'batch_id'    => $batchId,
                        'amount'      => $amount,
                        'type'        => 'partial',
                        'method'      => 'reallocation',
                        'reference'   => "Reallocated from {$creditOrder->order_number}",
                        'paid_at'     => now(),
                        'notes'       => "Auto-reallocated from overpayment on {$creditOrder->order_number}",
                        'recorded_by' => $recordedBy,
                    ]));

                    $this->recalcOrderPayment($creditOrder);
                    $this->recalcOrderPayment($shortOrder);

                    $transfers[] = [
                        'from'   => $creditOrder->order_number,
                        'to'     => $shortOrder->order_number,
                        'amount' => $amount,
                    ];

                    $needed -= $amount;
                    if ($needed <= 0) break;
                }
            }
        });

        if (!empty($transfers)) {
            $summary = collect($transfers)
                ->map(fn($t) => 'Rp'.number_format($t['amount'], 0, ',', '.')." from {$t['from']} to {$t['to']}")
                ->implode('; ');

            ActivityLog::record(
                'payment.auto_reallocated',
                "Auto-reallocated overpayment credit: {$summary}",
                'customer',
                $customerId
            );
        }
    }

    /**
     * How much this order holds above its own total that is safe to move:
     * the smaller of what it has recorded and what has been verified, minus
     * what it owes.
     */
    private function spare(Order $order): float
    {
        return min((float) $order->deposit_paid, $order->verifiedPaid()) - (float) $order->total_amount;
    }

    // Fix #1: delegate to Order::recalcPaymentStatus() — single source of truth.
    private function recalcOrderPayment(Order $order): void
    {
        $order->recalcPaymentStatus();
    }
}