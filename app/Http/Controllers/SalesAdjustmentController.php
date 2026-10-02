<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesAdjustment;
use App\Models\SalesAdjustmentItem;
use App\Services\PromoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalesAdjustmentController extends Controller
{
    public function __construct(protected PromoService $promoService) {}

    /**
     * Full history across every order — the "Returns & Credit Notes" page.
     */
    public function index(Request $request)
    {
        if (!Auth::user()->hasPermission('orders.sales_adjustments')) abort(403);

        $query = SalesAdjustment::with(['order.customer', 'trip', 'createdBy', 'items.product', 'items.variant'])
            ->latest();

        if ($request->filled('type')) $query->where('type', $request->type);
        if ($request->filled('trip_id')) $query->where('trip_id', $request->trip_id);
        if (Auth::user()->isOwnDataOnly()) $query->whereHas('order', fn ($q) => $q->where('created_by', Auth::id()));

        $adjustments = $query->paginate(30)->withQueryString();
        $trips = \App\Models\Trip::orderByDesc('id')->get();

        return view('sales-adjustments.index', compact('adjustments', 'trips'));
    }

    public function show(SalesAdjustment $salesAdjustment)
    {
        if (!Auth::user()->hasPermission('orders.sales_adjustments')) abort(403);
        $salesAdjustment->load(['order.customer', 'order.items.product', 'order.items.variant', 'trip', 'createdBy', 'items.product', 'items.variant', 'payment']);
        return view('sales-adjustments.show', compact('salesAdjustment'));
    }

    /**
     * Issue a Sales Return (goods back, tied to specific order items) or a
     * Credit Note (money back only, no items) against one order.
     */
    public function store(Request $request, Order $order)
    {
        if (!Auth::user()->hasPermission('orders.sales_adjustments')) abort(403);

        $request->validate([
            'type'   => 'required|in:return,credit_note',
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($request->type === 'return') {
            $this->storeReturn($request, $order);
        } else {
            $this->storeCreditNote($request, $order);
        }

        return back()->with('success', ucfirst(str_replace('_', ' ', $request->type)) . ' issued.');
    }

    private function storeReturn(Request $request, Order $order): void
    {
        $request->validate([
            'items'               => 'required|array|min:1',
            'items.*.order_item_id' => 'required|integer|exists:order_items,id',
            'items.*.quantity'    => 'required|integer|min:1',
            'refund_amount'       => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($request, $order) {
            $amount = 0;
            $lines  = [];

            foreach ($request->items as $line) {
                $item = OrderItem::where('id', $line['order_item_id'])->where('order_id', $order->id)->lockForUpdate()->first();
                abort_if(!$item, 404, 'Item does not belong to this order.');
                abort_if($line['quantity'] > $item->quantity, 422,
                    "Can't return {$line['quantity']} of {$item->product->product_code} — only {$item->quantity} left on this order.");

                $lineTotal = $item->unit_price * $line['quantity'];
                $amount   += $lineTotal;
                $lines[]   = [
                    'item' => $item, 'quantity' => $line['quantity'],
                    'unit_price' => $item->unit_price, 'line_total' => $lineTotal,
                ];
            }

            $number = SalesAdjustment::reserveNumber($order->trip, 'return');
            $adjustment = SalesAdjustment::create([
                'type' => 'return', 'adjustment_number' => $number,
                'order_id' => $order->id, 'trip_id' => $order->trip_id,
                'amount' => $amount, 'reason' => $request->reason, 'created_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                SalesAdjustmentItem::create([
                    'sales_adjustment_id' => $adjustment->id, 'order_item_id' => $line['item']->id,
                    'product_id' => $line['item']->product_id, 'product_variant_id' => $line['item']->product_variant_id,
                    'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => $line['line_total'],
                ]);
                // Shrink the original line by the returned quantity — same
                // "reduce in place" pattern as the PO-arrival correction, so
                // the existing recalculate() picks this up automatically via
                // its normal item-sum, no separate deduction step needed.
                $remaining = $line['item']->quantity - $line['quantity'];
                $line['item']->update([
                    'quantity'   => $remaining,
                    'line_total' => $line['item']->unit_price * $remaining,
                ]);
            }

            $this->applyRefundAndRecalc($order, $adjustment, (float) ($request->refund_amount ?? $amount));

            ActivityLog::record('sales_return.issued',
                "Sales Return {$number} issued on {$order->order_number} ({$order->customer->name}) — Rp " . number_format($amount, 0, ',', '.'),
                'sales_adjustment', $adjustment->id);
        });
    }

    private function storeCreditNote(Request $request, Order $order): void
    {
        $request->validate(['amount' => 'required|numeric|min:0.01']);

        DB::transaction(function () use ($request, $order) {
            $number = SalesAdjustment::reserveNumber($order->trip, 'credit_note');
            $adjustment = SalesAdjustment::create([
                'type' => 'credit_note', 'adjustment_number' => $number,
                'order_id' => $order->id, 'trip_id' => $order->trip_id,
                'amount' => $request->amount, 'reason' => $request->reason, 'created_by' => Auth::id(),
            ]);

            $this->applyRefundAndRecalc($order, $adjustment, (float) $request->amount);

            ActivityLog::record('credit_note.issued',
                "Credit Note {$number} issued on {$order->order_number} ({$order->customer->name}) — Rp " . number_format($request->amount, 0, ',', '.'),
                'sales_adjustment', $adjustment->id);
        });
    }

    /**
     * Shared by both types: recalculate the order (now reflecting either
     * the shrunk items or the credit-note deduction), then record the
     * actual cash refund as a normal type='refund' Payment — reusing
     * Order::recalcPaymentStatus()'s existing refund-aware math rather than
     * a second, parallel balance calculation.
     */
    private function applyRefundAndRecalc(Order $order, SalesAdjustment $adjustment, float $refundAmount): void
    {
        $this->promoService->recalcCustomerShipping($order->customer_id, $order->trip_id);
        $order->refresh();

        if ($refundAmount > 0) {
            \App\Models\Payment::create([
                'order_id' => $order->id, 'sales_adjustment_id' => $adjustment->id,
                'amount' => $refundAmount, 'type' => 'refund', 'method' => 'Refund',
                'reference' => $adjustment->adjustment_number,
                'paid_at' => now(), 'notes' => $adjustment->reason,
                'recorded_by' => Auth::id(),
                // Staff-issued, not a customer claim — doesn't need the
                // usual verification step, and leaving it unverified would
                // wrongly block Ready to Pack on an otherwise-settled order.
                'verification_status' => 'verified', 'verified_by' => Auth::id(), 'verified_at' => now(),
            ]);
        }

        $order->recalcPaymentStatus();
    }

    /**
     * Void a wrongly-issued return or credit note — reverses both the item
     * quantities (for a return) and the refund payment, then recalculates.
     * Never hard-deleted, same as voiding a payment.
     */
    public function void(Request $request, SalesAdjustment $salesAdjustment)
    {
        if (!Auth::user()->hasPermission('orders.sales_adjustments')) abort(403);
        abort_if($salesAdjustment->isVoided(), 422, 'Already voided.');

        $request->validate(['void_reason' => 'nullable|string|max:500']);

        DB::transaction(function () use ($request, $salesAdjustment) {
            $order = $salesAdjustment->order;

            if ($salesAdjustment->isReturn()) {
                foreach ($salesAdjustment->items as $line) {
                    $item = OrderItem::find($line->order_item_id);
                    if ($item) {
                        $restored = $item->quantity + $line->quantity;
                        $item->update(['quantity' => $restored, 'line_total' => $item->unit_price * $restored]);
                    }
                }
            }

            if ($salesAdjustment->payment) {
                $salesAdjustment->payment->update([
                    'voided_at' => now(), 'voided_by' => Auth::id(),
                    'void_reason' => 'Reversed — ' . $salesAdjustment->adjustment_number . ' voided',
                ]);
            }

            $salesAdjustment->update([
                'voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $request->void_reason,
            ]);

            $this->promoService->recalcCustomerShipping($order->customer_id, $order->trip_id);
            $order->refresh();
            $order->recalcPaymentStatus();

            ActivityLog::record('sales_adjustment.voided',
                "Voided {$salesAdjustment->adjustment_number} on {$order->order_number} ({$order->customer->name})",
                'sales_adjustment', $salesAdjustment->id);
        });

        return back()->with('success', "{$salesAdjustment->adjustment_number} voided.");
    }
}
