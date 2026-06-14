<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Payments home: outstanding balances + payment log.
     */
    public function index(Request $request)
    {
        if (!Auth::user()->hasPermission('payments.view')) {
            abort(403, 'You do not have permission to view payments.');
        }

        $trips = Trip::orderByDesc('id')->get();
        $tripId = $request->trip_id ?: ($trips->first()->id ?? null);
        $tab = $request->get('tab', 'outstanding');

        // ── Outstanding: customers with a balance due in this trip ──────
        $outstanding = collect();
        if ($tripId) {
            $outstanding = Order::with('customer')
                ->where('trip_id', $tripId)
                ->where('payment_status', '!=', 'paid')
                ->get()
                ->groupBy('customer_id')
                ->map(function ($orders) {
                    $cust = $orders->first()->customer;
                    $totalOrdered = $orders->sum('total_amount');
                    $totalPaid    = $orders->sum('deposit_paid');
                    return (object) [
                        'customer'      => $cust,
                        'order_count'   => $orders->count(),
                        'total_ordered' => $totalOrdered,
                        'total_paid'    => $totalPaid,
                        'balance_due'   => max(0, $totalOrdered - $totalPaid),
                    ];
                })
                ->filter(fn($row) => $row->balance_due > 0)
                ->sortByDesc('balance_due')
                ->values();
        }

        // ── Payment log: recent payments (optionally scoped to trip) ────
        $logQuery = Payment::with(['order.customer', 'order.trip', 'recordedBy'])
            ->orderByDesc('paid_at')->orderByDesc('id');
        if ($tripId) {
            $logQuery->whereHas('order', fn($q) => $q->where('trip_id', $tripId));
        }
        $log = $logQuery->paginate(50)->withQueryString();

        return view('payments.index', compact('trips', 'tripId', 'tab', 'outstanding', 'log'));
    }

    /**
     * Show the record-payment screen for one customer (their unpaid orders).
     */
    public function createForCustomer(Request $request, Customer $customer)
    {
        if (!Auth::user()->hasPermission('payments.record')) {
            abort(403, 'You do not have permission to record payments.');
        }

        $tripId = $request->trip_id;
        abort_if(!$tripId, 404, 'Trip is required.');

        $trip = Trip::findOrFail($tripId);

        // Unpaid orders for this customer in this trip, oldest first (FIFO allocation)
        $orders = Order::where('customer_id', $customer->id)
            ->where('trip_id', $tripId)
            ->where('payment_status', '!=', 'paid')
            ->orderBy('ordered_at')->orderBy('id')
            ->get();

        $totalDue = $orders->sum(fn($o) => max(0, $o->total_amount - $o->deposit_paid));

        return view('payments.create', compact('customer', 'trip', 'orders', 'totalDue'));
    }

    /**
     * Store a lump-sum payment, allocated across the customer's orders.
     * Each order gets its own payment row sharing a batch_id.
     */
    public function store(Request $request)
    {
        if (!Auth::user()->hasPermission('payments.record')) {
            abort(403, 'You do not have permission to record payments.');
        }

        $data = $request->validate([
            'customer_id'          => 'required|exists:customers,id',
            'trip_id'              => 'required|exists:trips,id',
            'method'               => 'nullable|string|max:50',
            'reference'            => 'nullable|string|max:100',
            'paid_at'              => 'required|date',
            'notes'                => 'nullable|string',
            'allocations'          => 'required|array|min:1',
            'allocations.*.order_id' => 'required|exists:orders,id',
            'allocations.*.amount'   => 'required|numeric|min:0',
        ]);

        // Filter to allocations with a positive amount
        $allocations = collect($data['allocations'])
            ->filter(fn($a) => (float) $a['amount'] > 0)
            ->values();

        if ($allocations->isEmpty()) {
            return back()->withInput()->with('error', 'Enter at least one amount to allocate.');
        }

        $batchId = (string) Str::uuid();
        $affectedOrderIds = [];

        DB::transaction(function () use ($allocations, $data, $batchId, &$affectedOrderIds) {
            foreach ($allocations as $alloc) {
                $order = Order::where('id', $alloc['order_id'])
                    ->where('trip_id', $data['trip_id'])
                    ->where('customer_id', $data['customer_id'])
                    ->first();
                if (!$order) continue;

                $order->payments()->create([
                    'batch_id'    => $batchId,
                    'amount'      => $alloc['amount'],
                    'type'        => 'partial',
                    'method'      => $data['method'] ?? 'transfer',
                    'reference'   => $data['reference'] ?? null,
                    'paid_at'     => $data['paid_at'],
                    'notes'       => $data['notes'] ?? null,
                    'recorded_by' => Auth::id(),
                ]);
                $affectedOrderIds[] = $order->id;
            }

            // Recalculate each affected order's payment status
            foreach (array_unique($affectedOrderIds) as $oid) {
                $this->recalcOrderPayment(Order::find($oid));
            }
        });

        $total = $allocations->sum(fn($a) => (float) $a['amount']);
        return redirect()->route('payments.index', ['trip_id' => $data['trip_id']])
            ->with('success', 'Payment of Rp ' . number_format($total, 0, ',', '.') .
                ' recorded across ' . count(array_unique($affectedOrderIds)) . ' order(s).');
    }

    /**
     * Void an entire payment batch (all rows sharing the batch_id),
     * or a single payment if it has no batch.
     */
    public function voidBatch(Request $request, string $batchId)
    {
        if (!Auth::user()->hasPermission('payments.void')) {
            abort(403, 'You do not have permission to void payments.');
        }

        $payments = Payment::where('batch_id', $batchId)->whereNull('voided_at')->get();
        if ($payments->isEmpty()) {
            return back()->with('error', 'No active payments found for this batch.');
        }

        $affectedOrderIds = [];
        DB::transaction(function () use ($payments, $request, &$affectedOrderIds) {
            foreach ($payments as $payment) {
                $payment->update([
                    'voided_at'   => now(),
                    'voided_by'   => Auth::id(),
                    'void_reason' => $request->input('void_reason', 'Batch voided'),
                ]);
                $affectedOrderIds[] = $payment->order_id;
            }
            foreach (array_unique($affectedOrderIds) as $oid) {
                $this->recalcOrderPayment(Order::find($oid));
            }
        });

        return back()->with('success', 'Payment batch voided. Affected order balances restored.');
    }

    /**
     * Recalculate an order's deposit_paid + payment_status from its
     * non-voided payment rows. Mirrors OrderController::recalcOrderPayment.
     */
    private function recalcOrderPayment(?Order $order): void
    {
        if (!$order) return;
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