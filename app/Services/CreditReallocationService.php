<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * After a price sync (product price change or shipping rate change)
 * updates order totals, a customer can end up with one order overpaid
 * and another underpaid within the same trip — the money was recorded
 * per-order, but the totals shifted per-order too.
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

        $overpaid  = $orders->filter(fn($o) => (float) $o->deposit_paid > (float) $o->total_amount)->values();
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
                    $available = (float) $creditOrder->deposit_paid - (float) $creditOrder->total_amount;
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

    /** Same math as PaymentController::recalcOrderPayment() — kept in sync intentionally. */
    private function recalcOrderPayment(Order $order): void
    {
        $payments = $order->payments()->whereNull('voided_at')->get();
        $paid = $payments->where('type', '!=', 'refund')->sum('amount')
              - $payments->where('type', 'refund')->sum('amount');
        $status = $paid <= 0 ? 'unpaid'
            : ($paid >= $order->total_amount ? 'paid' : 'partial');
        if ($status === 'paid') {
            $order->items()->where('status', 'pending')->update(['status' => 'confirmed']);
        }
        $order->update(['deposit_paid' => max(0, $paid), 'payment_status' => $status]);
    }
}