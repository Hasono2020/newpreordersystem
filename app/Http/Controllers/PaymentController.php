<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\Customer;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    use \App\Traits\HandlesXlsx;

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
        if ($tab === 'pack') $tab = 'shipment'; // the old name of this tab, for bookmarks and remembered links
        $search = trim($request->get('search', ''));
        $createdByFilter = !Auth::user()->isOwnDataOnly() ? $request->get('created_by', '') : '';

        // ── Outstanding: pure aggregate query — only_full_group_by safe ──────
        $outstanding = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50);
        if ($tripId) {
            $tid = (int) $tripId; // safe int for subquery interpolation
            $query = DB::table('orders')
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->select([
                    'orders.customer_id',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone',
                    'customers.type as customer_type',
                    DB::raw('COUNT(orders.id) as order_count'),
                    DB::raw('SUM(orders.total_amount) as total_ordered'),
                    DB::raw('SUM(orders.deposit_paid) as total_paid'),
                    DB::raw('(SUM(orders.total_amount) - SUM(orders.deposit_paid)) as balance_due'),
                    DB::raw('COUNT(DISTINCT orders.created_by) as creator_count'),
                    DB::raw('MAX(creators.name) as creator_name'),
                    // Per-customer payment verification counts (non-voided payments on this customer's orders in this trip).
                    // $tid is an int cast of the trip id — safe to interpolate; statuses are hardcoded literals.
                    DB::raw("(SELECT COUNT(*) FROM payments p JOIN orders po ON po.id = p.order_id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tid} AND p.voided_at IS NULL) as pay_total"),
                    DB::raw("(SELECT COUNT(*) FROM payments p JOIN orders po ON po.id = p.order_id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tid} AND p.voided_at IS NULL AND p.verification_status = 'verified') as pay_verified"),
                    DB::raw("(SELECT COUNT(*) FROM payments p JOIN orders po ON po.id = p.order_id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tid} AND p.voided_at IS NULL AND p.verification_status = 'unverified') as pay_unverified"),
                    DB::raw("(SELECT COUNT(*) FROM payments p JOIN orders po ON po.id = p.order_id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tid} AND p.voided_at IS NULL AND p.verification_status = 'disputed') as pay_disputed"),
                ])
                ->join('users as creators', 'creators.id', '=', 'orders.created_by')
                ->where('orders.trip_id', $tripId)
                // Deliberately NOT filtering out payment_status = 'paid' orders here:
                // payments are recorded per-order, but a price sync (product price or
                // shipping rate change) can leave one order overpaid and another
                // underpaid for the same customer at the same time. Summing across
                // ALL of the customer's orders in this trip — not just the ones that
                // individually look "unpaid" — gives their TRUE net position, so a
                // customer who's actually in credit overall (even if one specific
                // order still shows a gap) correctly nets to a non-positive balance
                // and gets excluded below by having('balance_due', '>', 0), instead
                // of confusingly showing both a balance due AND a credit at once.
                ->when(Auth::user()->isOwnDataOnly(), fn($q) => $q->where('orders.created_by', Auth::id()))
                ->when($createdByFilter, fn($q) => $q->where('orders.created_by', $createdByFilter))
                ->when($search && !$this->isMoneySearch($search), fn($q) => $q->where(fn($w) =>
                    $w->where('customers.name', 'like', "%{$search}%")
                      ->orWhere('customers.phone', 'like', "%{$search}%")
                ))
                ->groupBy(
                    'orders.customer_id',
                    'customers.name',
                    'customers.phone',
                    'customers.type'
                )
                // Note: created_by_names uses GROUP_CONCAT so no groupBy needed
                ->having('balance_due', '>', 0);

            // A purely numeric search matches ANY of the three amounts:
            // total ordered, total paid, or balance due.
            if ($search && $this->isMoneySearch($search)) {
                $amt = (int) preg_replace('/[^0-9]/', '', $search);
                $query->havingRaw(
                    '(SUM(orders.total_amount) = ? OR SUM(orders.deposit_paid) = ? OR (SUM(orders.total_amount) - SUM(orders.deposit_paid)) = ?)',
                    [$amt, $amt, $amt]
                );
            }

            $query->orderByDesc('balance_due');

            $outstanding = $query->paginate(50, ['*'], 'outstanding_page')
                ->withQueryString();

        }

        // ── Payment log: recent payments (optionally scoped to trip) ────
        $logQuery = Payment::with(['order.customer', 'order.trip', 'order.createdBy', 'recordedBy', 'verifiedBy'])
            ->orderByDesc('paid_at')->orderByDesc('id');
        if ($tripId) {
            $logQuery->whereHas('order', fn($q) => $q->where('trip_id', $tripId));
        }
        // Staff with own_data only see payments for orders they created
        if (Auth::user()->isOwnDataOnly()) {
            $logQuery->whereHas('order', fn($q) => $q->where('created_by', Auth::id()));
        }
        if ($search) {
            // If the search looks like money (digits, dots, commas, optional 'Rp'), match the amount too.
            $numeric = preg_replace('/[^0-9]/', '', $search);
            $logQuery->where(function ($q) use ($search, $numeric) {
                $q->whereHas('order.customer', fn($c) =>
                        $c->where('name', 'like', "%{$search}%")
                          ->orWhere('phone', 'like', "%{$search}%"))
                  ->orWhereHas('order', fn($o) => $o->where('order_number', 'like', "%{$search}%"))
                  ->orWhere('reference', 'like', "%{$search}%");
                if ($numeric !== '' && strlen($numeric) >= 3) {
                    $q->orWhere('amount', (int) $numeric);
                }
            });
        }

        $verificationFilter = $request->get('verification_status', '');
        if ($verificationFilter) {
            $logQuery->where('verification_status', $verificationFilter);
        }

        // Filter by order creator (admin/finance only — staff already scoped)
        // createdByFilter applied to log below
        if ($createdByFilter) {
            $logQuery->whereHas('order', fn($q) => $q->where('created_by', $createdByFilter));
        }

        $log = $logQuery->paginate(50)->withQueryString();

        // Batch metadata: for each batch_id on this page, total amount + order count.
        // Lets finance see that N payment rows came from ONE customer transfer.
        $batchIds = collect($log->items())->pluck('batch_id')->filter()->unique()->values();
        $batchMeta = [];
        if ($batchIds->isNotEmpty()) {
            $batchMeta = Payment::whereIn('batch_id', $batchIds)
                ->whereNull('voided_at')
                ->selectRaw('batch_id, COUNT(*) as cnt, SUM(amount) as total')
                ->groupBy('batch_id')
                ->get()
                ->keyBy('batch_id')
                ->map(fn($r) => ['count' => (int) $r->cnt, 'total' => (float) $r->total])
                ->all();
        }

        // Verification counts for the tab badges and summary bar (scoped to trip)
        $vcBase = Payment::whereHas('order', fn($q) => $q->where('trip_id', $tripId));
        $vcBase->whereNull('voided_at');
        if (Auth::user()->isOwnDataOnly()) {
            $vcBase->whereHas('order', fn($q) => $q->where('created_by', Auth::id()));
        }
        // Just the three counts. (There used to be a "Total Verified (Rp)" money total here; it was
        // removed from the Payment Log on request, so the amount is no longer worked out at all.)
        $verificationCounts = [
            'unverified' => (clone $vcBase)->where('verification_status', 'unverified')->count(),
            'verified'   => (clone $vcBase)->where('verification_status', 'verified')->count(),
            'disputed'   => (clone $vcBase)->where('verification_status', 'disputed')->count(),
        ];

        // ── Shipment: customers whose orders in this trip are ALL paid + verified, with what was sent ──
        $readyToPack = collect();
        $readyCount  = 0;
        $toShipCount = 0;    // nothing sent yet
        $partialCount = 0;   // some sent, some still to send
        $shippedCount = 0;   // everything sent
        $shipBatches = collect();
        $shipFilter  = in_array($request->get('ship'), ['pending', 'partial', 'shipped'], true) ? $request->get('ship') : '';
        if ($tripId) {
            $tidInt = (int) $tripId;
            $custQuery = DB::table('orders')
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->leftJoin('shipments', fn($j) => $j->on('shipments.customer_id', '=', 'orders.customer_id')
                                                    ->on('shipments.trip_id', '=', 'orders.trip_id'))
                ->where('orders.trip_id', $tidInt)
                ->when(Auth::user()->isOwnDataOnly(), fn($q) => $q->where('orders.created_by', Auth::id()))
                ->when($createdByFilter, fn($q) => $q->where('orders.created_by', $createdByFilter))
                ->when($search && !$this->isMoneySearch($search), fn($q) => $q->where(fn($w) =>
                    $w->where('customers.name', 'like', "%{$search}%")
                      ->orWhere('customers.phone', 'like', "%{$search}%")))
                ->groupBy('orders.customer_id', 'customers.name', 'customers.phone')
                ->select([
                    'orders.customer_id',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone',
                    DB::raw('COUNT(orders.id) as order_count'),
                    DB::raw('SUM(orders.total_amount) as total_amount'),
                    DB::raw("SUM(CASE WHEN orders.payment_status != 'paid' THEN 1 ELSE 0 END) as unpaid_count"),
                    DB::raw("(SELECT COUNT(DISTINCT po.id) FROM orders po JOIN payments p ON p.order_id = po.id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tidInt} AND p.voided_at IS NULL AND p.verification_status != 'verified') as unverified_order_count"),
                    DB::raw('MAX(orders.invoice_printed_at) as printed_at'),
                    // One shipments row per customer+trip, so MAX() just picks that row's value.
                    DB::raw('MAX(shipments.package_total) as package_total'),
                    DB::raw("(SELECT COALESCE(SUM(sb.packages), 0) FROM shipment_batches sb JOIN shipments sh ON sh.id = sb.shipment_id WHERE sh.customer_id = orders.customer_id AND sh.trip_id = {$tidInt}) as packages_sent"),
                ])
                ->having('unpaid_count', '=', 0)
                ->having('unverified_order_count', '=', 0)
                ->when($search && $this->isMoneySearch($search), fn($q) =>
                    $q->havingRaw('SUM(orders.total_amount) = ?', [(int) preg_replace('/[^0-9]/', '', $search)]))
                ->orderBy('customers.name');

            // Counts for the tab badge and the All / To ship / Partial / Shipped buttons (they follow the search box).
            //   Shipped = every package sent;  Partial = some sent;  To ship = none sent yet.
            $doneSql    = '(package_total IS NOT NULL AND packages_sent >= package_total)';
            $partialSql = "(packages_sent > 0 AND NOT {$doneSql})";
            $shipCounts = DB::query()->fromSub((clone $custQuery)->reorder(), 'ready')
                ->selectRaw("COUNT(*) as total,
                    COALESCE(SUM(CASE WHEN {$doneSql} THEN 1 ELSE 0 END), 0) as done,
                    COALESCE(SUM(CASE WHEN {$partialSql} THEN 1 ELSE 0 END), 0) as partial")
                ->first();
            $shippedCount = (int) $shipCounts->done;
            $partialCount = (int) $shipCounts->partial;
            $toShipCount  = (int) $shipCounts->total - $shippedCount - $partialCount;

            $custQuery->when($shipFilter === 'pending', fn($q) => $q->havingRaw('packages_sent = 0'))
                      ->when($shipFilter === 'partial', fn($q) => $q->havingRaw($partialSql))
                      ->when($shipFilter === 'shipped', fn($q) => $q->havingRaw($doneSql));

            $readyToPack = $custQuery->paginate(50, ['*'], 'pack_page')->withQueryString();
            $readyCount  = $readyToPack->total();

            // Every sending logged for the customers on this page, oldest first.
            $shipBatches = ShipmentBatch::query()
                ->join('shipments', 'shipments.id', '=', 'shipment_batches.shipment_id')
                ->where('shipments.trip_id', $tidInt)
                ->whereIn('shipments.customer_id', $readyToPack->pluck('customer_id'))
                ->orderBy('shipment_batches.shipped_on')->orderBy('shipment_batches.id')
                ->get(['shipment_batches.*', 'shipments.customer_id as customer_id'])
                ->groupBy('customer_id');
        }

        $staffList = \App\Models\User::where('is_active', true)->orderBy('name')->get(['id','name','role']);
        // ── Overpaid / Credit: customers who paid MORE than their order total (owed a refund) ──
        $overpaid = collect();
        if ($tripId) {
            $tidC = (int) $tripId;
            $overpaid = DB::table('orders')
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->where('orders.trip_id', $tidC)
                ->when(Auth::user()->isOwnDataOnly(), fn($q) => $q->where('orders.created_by', Auth::id()))
                ->when($createdByFilter, fn($q) => $q->where('orders.created_by', $createdByFilter))
                ->when($search, fn($q) => $q->where(fn($w) =>
                    $w->where('customers.name', 'like', "%{$search}%")
                      ->orWhere('customers.phone', 'like', "%{$search}%")))
                ->groupBy('orders.customer_id', 'customers.name', 'customers.phone')
                ->select([
                    'orders.customer_id',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone',
                    DB::raw('SUM(orders.total_amount) as total_ordered'),
                    DB::raw('SUM(orders.deposit_paid) as total_paid'),
                    DB::raw('(SUM(orders.deposit_paid) - SUM(orders.total_amount)) as credit'),
                    // The part of what this customer has paid that someone has
                    // actually CONFIRMED arrived (net of refunds). Only this part
                    // can be refunded — see Order::verifiedPaid().
                    DB::raw("(SELECT COALESCE(SUM(CASE WHEN p.type = 'refund' THEN -p.amount WHEN p.verification_status = 'verified' THEN p.amount ELSE 0 END), 0)
                              FROM payments p JOIN orders po ON po.id = p.order_id
                              WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tidC} AND p.voided_at IS NULL) as verified_paid"),
                ])
                ->having('credit', '>', 0)
                ->orderByDesc('credit')
                ->get();

            if ($overpaid->isNotEmpty()) {
                $ordersByCustomer = DB::table('orders')
                    ->where('trip_id', $tidC)
                    ->whereIn('customer_id', $overpaid->pluck('customer_id'))
                    ->orderBy('id')
                    ->select('id', 'customer_id', 'order_number', 'total_amount', 'deposit_paid')
                    ->selectRaw("(SELECT COALESCE(SUM(CASE WHEN p.type = 'refund' THEN -p.amount WHEN p.verification_status = 'verified' THEN p.amount ELSE 0 END), 0)
                                  FROM payments p WHERE p.order_id = orders.id AND p.voided_at IS NULL) as verified_paid")
                    ->get()
                    ->groupBy('customer_id');

                $overpaid = $overpaid->map(function ($oc) use ($ordersByCustomer) {
                    $oc->orders = $ordersByCustomer->get($oc->customer_id, collect())->values();
                    // How much of the credit is safe to refund now (verified),
                    // and how much is still waiting for someone to confirm the
                    // transfer actually arrived.
                    $oc->verified_credit   = max(0.0, (float) $oc->verified_paid - (float) $oc->total_ordered);
                    $oc->unverified_credit = max(0.0, (float) $oc->credit - $oc->verified_credit);

                    // A Credit Note refunds money, so it has to come out of the
                    // order that holds the customer's spare VERIFIED credit —
                    // verified above its own total. Not simply "paid the most":
                    // after credit is moved between orders, one can hold lots of
                    // money that is all needed for its own total. Oldest wins a tie.
                    $oc->defaultOrder = $oc->orders->sort(function ($a, $b) {
                        $surplusA = (float) $a->verified_paid - (float) $a->total_amount;
                        $surplusB = (float) $b->verified_paid - (float) $b->total_amount;
                        return [$surplusB, (float) $b->verified_paid] <=> [$surplusA, (float) $a->verified_paid];
                    })->first();
                    return $oc;
                });
            }
        }

        // For the CS checklist in the Excel export pop-up.
        $csAgents = \App\Models\CsAgent::orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']);

        return view('payments.index', compact('trips', 'tripId', 'tab', 'outstanding', 'log', 'search', 'verificationFilter', 'verificationCounts', 'createdByFilter', 'staffList', 'readyToPack', 'readyCount', 'toShipCount', 'partialCount', 'shippedCount', 'shipFilter', 'shipBatches', 'batchMeta', 'overpaid', 'csAgents'));
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

        // Orders that still need money allocated — filtered by the ACTUAL
        // paid-vs-total numbers (whereColumn), not the payment_status label.
        // payment_status can go stale relative to the real figures (e.g. an
        // order that became overpaid via a price drop but was never
        // re-derived), and filtering on a stale label previously let an
        // already-overpaid order sit in this list with its balance
        // silently clamped to 0 instead of being excluded outright.
        $orders = Order::where('customer_id', $customer->id)
            ->where('trip_id', $tripId)
            ->whereColumn('deposit_paid', '<', 'total_amount')
            ->orderBy('ordered_at')->orderBy('id')
            ->get();

        // Total actually owed = net across ALL of the customer's orders in
        // this trip, including any that are overpaid. Summing only the
        // (clamped-to-zero) balances of the orders above ignores credit
        // sitting on an overpaid order entirely — a customer with Rp160,000
        // credit on one order and Rp1,550,000 owed across others truly
        // owes Rp1,390,000 net, not Rp1,550,000. Matches the same net
        // calculation the Outstanding Balances tab already uses.
        $tripTotals = Order::where('customer_id', $customer->id)
            ->where('trip_id', $tripId)
            ->selectRaw('SUM(total_amount) as total, SUM(deposit_paid) as paid')
            ->first();
        // (float) wraps max() itself, not just its arguments: PHP's max()
        // returns whichever argument it received literally when there's a
        // tie, so max(0, 0.0) returns int 0, not float 0.0 — this bit a
        // test the moment there was no overpayment (a genuine 0 result).
        $totalDue = (float) max(0, (float) ($tripTotals->total ?? 0) - (float) ($tripTotals->paid ?? 0));

        // Surface the gap (if any) so staff aren't left guessing why the
        // total above doesn't match a naive sum of the rows below.
        $clampedSum     = $orders->sum(fn($o) => max(0, $o->total_amount - $o->deposit_paid));
        $strandedCredit = (float) max(0, $clampedSum - $totalDue);

        return view('payments.create', compact('customer', 'trip', 'orders', 'totalDue', 'strandedCredit'));
    }

    /**
     * Store a lump-sum payment, allocated across the customer's orders.
     * Each order gets its own payment row sharing a batch_id.
     */
    /**
     * True if the search string looks like a money amount (digits only after
     * stripping Rp / dots / commas / spaces) of at least 3 digits.
     */
    private function isMoneySearch(?string $search): bool
    {
        if (!$search) return false;
        $digits = preg_replace('/[^0-9]/', '', $search);
        // Must be all digits once formatting is removed, and long enough to be an amount
        $stripped = str_ireplace(['rp', ' ', '.', ','], '', $search);
        return ctype_digit($stripped) && strlen($digits) >= 3;
    }

    public function store(Request $request)
    {
        if (!Auth::user()->hasPermission('payments.record')) {
            abort(403, 'You do not have permission to record payments.');
        }

        $data = $request->validate([
            'customer_id'          => 'required|exists:customers,id',
            'trip_id'              => 'required|exists:trips,id',
            'method'               => 'nullable|string|max:50|not_in:reallocation',
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
                // Fix #9: throw so the transaction rolls back and user sees an error,
                // rather than silently skipping and showing a misleading success message.
                if (!$order) {
                    throw new \RuntimeException("Order #{$alloc['order_id']} does not belong to this customer or trip.");
                }

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

            foreach (array_unique($affectedOrderIds) as $oid) {
                $this->recalcOrderPayment(Order::find($oid), false);
            }
            app(\App\Services\CreditReallocationService::class)
                ->reconcile((int) $data['customer_id'], (int) $data['trip_id']);
        });

        $total = $allocations->sum(fn($a) => (float) $a['amount']);
        $orderCount = count(array_unique($affectedOrderIds));
        $customer = Customer::find($data['customer_id']);

        \App\Models\ActivityLog::record(
            'payment.recorded',
            'Recorded Rp ' . number_format($total, 0, ',', '.') . " across {$orderCount} order(s) for " . ($customer->name ?? 'customer'),
            'customer',
            $data['customer_id']
        );

        return redirect()->route('payments.index', ['trip_id' => $data['trip_id']])
            ->with('success', 'Payment of Rp ' . number_format($total, 0, ',', '.') .
                ' recorded across ' . $orderCount . ' order(s).');
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

        $voidedTotal = $payments->sum('amount');
        $voidedCount = $payments->count();
        $reason = $request->input('void_reason', 'Batch voided');

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
                $this->recalcOrderPayment(Order::find($oid), false);
            }
            Order::whereIn('id', array_unique($affectedOrderIds))->get(['id', 'customer_id', 'trip_id'])
                ->unique(fn ($o) => $o->customer_id . '-' . $o->trip_id)
                ->each(fn ($o) => app(\App\Services\CreditReallocationService::class)->reconcile($o->customer_id, $o->trip_id));
        });

        \App\Models\ActivityLog::record(
            'payment.batch_voided',
            "Voided payment batch ({$voidedCount} payment(s), Rp " . number_format($voidedTotal, 0, ',', '.') . ") — reason: {$reason}",
            'customer',
            null
        );

        return back()->with('success', 'Payment batch voided. Affected order balances restored.');
    }


    /**
     * Export payments for a trip — two-sheet xlsx:
     *   Sheet 1 — Outstanding Balances
     *   Sheet 2 — Payment Log
     */
    public function export(Request $request)
    {
        if (!Auth::user()->hasPermission('payments.export')) {
            abort(403);
        }

        $trips  = Trip::orderByDesc('id')->get();
        $tripId = $request->trip_id ?: ($trips->first()->id ?? null);
        $trip   = Trip::find($tripId);
        $label  = $trip ? preg_replace('/[^\w\-]/', '_', $trip->name) : 'trip';

        // Optional CS checklist (same as the orders export): the CS AGENT who handled the
        // order — not the staff account that typed it in. "none" = orders with no CS agent.
        // Nothing ticked = everything, as before.
        $csPicked = array_values(array_filter(
            array_map(fn ($v) => is_scalar($v) ? (string) $v : '', (array) $request->query('cs_agent_ids', [])),
            fn ($v) => $v !== ''
        ));

        // ── Sheet 1: Outstanding Balances ─────────────────────────────
        // With a CS ticked this lists the CUSTOMERS who have at least one order handled by
        // that CS, and their figures still add up ALL their orders in the trip — a balance
        // worked out from only some of a customer's orders would be wrong.
        $sheet1 = [
            ['Customer', 'Phone', 'Type', 'Orders', 'Total Ordered (Rp)', 'Total Paid (Rp)', 'Balance Due (Rp)'],
        ];
        $orders = Order::with('customer')
            ->where('trip_id', $tripId)
            ->when($csPicked !== [], fn ($q) => $q->whereIn(
                'customer_id',
                Order::select('orders.customer_id')->where('orders.trip_id', $tripId)->handledByCs($csPicked)
            ))
            ->get()
            ->groupBy('customer_id');
        foreach ($orders as $customerOrders) {
            $c = $customerOrders->first()->customer;
            $ordered = $customerOrders->sum('total_amount');
            $paid    = $customerOrders->sum('deposit_paid');
            $balance = $ordered - $paid;
            // Same reasoning as the Outstanding Balances tab: sum across ALL
            // of the customer's orders in the trip (not just ones that look
            // individually unpaid), so an overpayment on one order nets
            // against a shortfall on another. Only list them here if they
            // have a genuine net-positive balance left.
            if ($balance <= 0) {
                continue;
            }
            $sheet1[] = [
                $c->name ?? '—', $c->phone ?? '—', $c->type ?? '—',
                $customerOrders->count(), $ordered, $paid, $balance,
            ];
        }

        // ── Sheet 2: Payment Log ──────────────────────────────────────
        $sheet2 = [
            ['Date', 'Customer', 'Phone', 'Order Number', 'Amount (Rp)', 'Method', 'Reference', 'Notes', 'Recorded By', 'Voided'],
        ];
        // Payment Log: each payment belongs to one order, so with a CS ticked it lists the
        // payments made on the orders that CS handled.
        $payments = Payment::with(['order.customer', 'recordedBy'])
            ->whereHas('order', fn($q) => $q->where('trip_id', $tripId)->handledByCs($csPicked))
            ->orderBy('paid_at', 'desc')
            ->get();
        foreach ($payments as $p) {
            $sheet2[] = [
                $p->paid_at?->format('d/m/Y') ?? '—',
                $p->order->customer->name ?? '—',
                $p->order->customer->phone ?? '—',
                $p->order->order_number ?? '—',
                $p->amount,
                ucfirst($p->method ?? '—'),
                $p->reference ?? '—',
                $p->notes ?? '—',
                $p->recordedBy->name ?? '—',
                $p->isVoided() ? 'Yes' : 'No',
            ];
        }

        // Audit trail: who downloaded the payments file, for which trip and (if ticked) which CS.
        \App\Models\ActivityLog::record(
            'payment.exported',
            'Exported payments to Excel for ' . ($trip ? "\"{$trip->name}\"" : 'a trip')
                . ' — ' . $payments->count() . ' payment(s), ' . (count($sheet1) - 1) . ' customer(s) owing'
                . ($csPicked !== [] ? ' (CS: ' . \App\Models\CsAgent::describePick($csPicked) . ')' : ''),
            $trip ? 'trip' : null,
            $trip?->id
        );

        // ── Build two-sheet xlsx manually ─────────────────────────────
        $filename = "payments_{$label}" . ($csPicked !== [] ? '_filtered-by-CS' : '') . ".xlsx";
        $xlsx = $this->buildMultiSheetXlsx([
            'Outstanding Balances' => $sheet1,
            'Payment Log'          => $sheet2,
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_dl_');
        file_put_contents($tmp, $xlsx);
        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Build a multi-sheet xlsx binary from an associative array of
     * sheetName => rows[][].
     */
    /**
     * Build a multi-sheet xlsx binary. $sheets = ['Sheet Name' => $rows2d, ...]
     */
    private function buildMultiSheetXlsx(array $sheets): string
    {
        // Collect shared strings across all sheets
        $sharedStrings = [];
        $ssIndex = [];
        foreach ($sheets as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $cell) {
                    $s = (string) $cell;
                    if (!is_numeric($cell) && $s !== '' && !isset($ssIndex[$s])) {
                        $ssIndex[$s] = count($sharedStrings);
                        $sharedStrings[] = $s;
                    }
                }
            }
        }

        // Build each sheet XML
        $sheetXmls    = [];
        $sheetEntries = '';
        $relEntries   = '';
        $ctEntries    = '';
        $id = 1;

        foreach ($sheets as $name => $rows) {
            $sheetRows = '';
            foreach ($rows as $ri => $row) {
                $rowNum = $ri + 1;
                $cells  = '';
                foreach ($row as $ci => $cell) {
                    $col = $this->xlsxIndexToCol($ci);
                    $ref = $col . $rowNum;
                    $s   = (string) $cell;
                    if ($s === '') {
                        $cells .= '<c r="' . $ref . '"/>';
                    } elseif (is_numeric($cell)) {
                        $cells .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
                    } else {
                        $idx    = $ssIndex[$s];
                        $cells .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
                    }
                }
                $sheetRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
            }

            $sheetXmls['xl/worksheets/sheet' . $id . '.xml'] =
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';

            $safeName      = htmlspecialchars($name, ENT_XML1, 'UTF-8');
            $sheetEntries .= '<sheet name="' . $safeName . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $relEntries   .= '<Relationship Id="rId' . $id . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . $id . '.xml"/>';
            $ctEntries    .= '<Override PartName="/xl/worksheets/sheet' . $id . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $id++;
        }

        // Shared strings XML
        $ssEntries = '';
        foreach ($sharedStrings as $str) {
            $ssEntries .= '<si><t>' . htmlspecialchars($str, ENT_XML1, 'UTF-8') . '</t></si>';
        }
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">'
            . $ssEntries . '</sst>';

        $ssRelId = $id;

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetEntries . '</sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relEntries
            . '<Relationship Id="rId' . $ssRelId . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"'
            . ' Target="sharedStrings.xml"/>'
            . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $ctEntries
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        $dotRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',       $contentTypes);
        $zip->addFromString('_rels/.rels',               $dotRels);
        $zip->addFromString('xl/workbook.xml',           $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels',$workbookRels);
        $zip->addFromString('xl/sharedStrings.xml',      $ssXml);
        foreach ($sheetXmls as $path => $xml) {
            $zip->addFromString($path, $xml);
        }
        $zip->close();

        $content = file_get_contents($tmp);
        unlink($tmp);
        return $content;
    }


    public function verify(Request $request, Payment $payment)
    {
        if (!Auth::user()->hasPermission('payments.verify')) abort(403);
        if ($payment->isVoided()) return back()->with('error', 'Cannot verify a voided payment.');
        $payment->update([
            'verification_status' => 'verified',
            'verified_by'         => Auth::id(),
            'verified_at'         => now(),
            'dispute_note'        => null,
        ]);
        $this->rebalanceAfterVerification($payment->order);
        return back()->with('success', 'Payment of Rp ' . number_format($payment->amount, 0, ',', '.') . ' verified.');
    }

    /**
     * Verify an entire payment batch at once (all non-voided rows sharing the batch_id).
     * Useful when one customer transfer was split across several orders.
     */
    /** Fully paid + every payment verified: the only customers that can be shipped. */
    private function isReadyToShip(int $customerId, int $tripId): bool
    {
        $orders = Order::where('customer_id', $customerId)->where('trip_id', $tripId)
            ->when(Auth::user()->isOwnDataOnly(), fn($q) => $q->where('created_by', Auth::id()))
            ->get();

        return $orders->isNotEmpty()
            && $orders->every(fn($o) => $o->payment_status === 'paid')
            && !Payment::whereIn('order_id', $orders->pluck('id'))
                ->whereNull('voided_at')->where('verification_status', '!=', 'verified')->exists();
    }

    /** Staff limited to their own data may only touch customers they have orders for. */
    private function authorizeShipmentAccess(int $customerId, int $tripId): void
    {
        if (!Auth::user()->isOwnDataOnly()) return;
        $mine = Order::where('customer_id', $customerId)->where('trip_id', $tripId)->where('created_by', Auth::id())->exists();
        if (!$mine) abort(403);
    }

    /** Back to the Shipment tab, on that customer's row. */
    private function shipmentBack(Customer $customer, string $kind, string $message)
    {
        return back()->with($kind, $message)
            ->with('shipment_saved', $kind === 'success' ? $customer->id : null)
            ->withFragment('ship-' . $customer->id);
    }

    /**
     * Shipment tab: set the TOTAL number of packages for a customer in a trip.
     *
     * Only customers who are fully paid (every order paid, every payment verified) can be shipped.
     * The total can be changed later, but never below what has already been sent. An empty total is
     * refused (so a saved number is never wiped by accident); removing the whole shipment — total and
     * everything sent — is its own explicit action (clear=1, behind a confirmation button).
     */
    public function saveShipment(Request $request)
    {
        $data = $request->validate([
            'customer_id'   => 'required|exists:customers,id',
            'trip_id'       => 'required|exists:trips,id',
            'package_total' => 'nullable|integer|min:1|max:9999',
            'clear'         => 'nullable|boolean',
        ]);
        $customerId = (int) $data['customer_id'];
        $tripId     = (int) $data['trip_id'];
        $customer   = Customer::findOrFail($customerId);

        $this->authorizeShipmentAccess($customerId, $tripId);
        if (!$this->isReadyToShip($customerId, $tripId)) {
            return $this->shipmentBack($customer, 'error', "{$customer->name} is not fully paid and verified yet, so it cannot be shipped.");
        }

        $keys     = ['customer_id' => $customerId, 'trip_id' => $tripId];
        $existing = Shipment::where($keys)->first();

        if ($request->boolean('clear')) {
            $existing?->delete(); // takes every sending logged for it along
            return $this->shipmentBack($customer, 'success', "Shipment removed for {$customer->name}.");
        }

        $total = $data['package_total'] ?? null;
        if ($total === null) {
            return $this->shipmentBack($customer, 'error', "Enter the total number of packages for {$customer->name}.");
        }

        $sent = $existing ? $existing->packagesSent() : 0;
        if ($total < $sent) {
            return $this->shipmentBack($customer, 'error', "{$sent} package(s) are already sent to {$customer->name}, so the total cannot be {$total}. Change or delete a sending first.");
        }

        Shipment::updateOrCreate($keys, ['package_total' => $total, 'updated_by' => Auth::id()]);

        return $this->shipmentBack($customer, 'success', "Saved for {$customer->name}: {$total} package(s) in total, {$sent} sent so far.");
    }

    /**
     * Log one sending: some of the customer's packages, on a date (today if left blank).
     * "rest=1" sends everything that is still left. Never more than the total.
     */
    public function storeShipmentBatch(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'trip_id'     => 'required|exists:trips,id',
            'packages'    => 'nullable|integer|min:1|max:9999',
            'shipped_on'  => 'nullable|date',
            'rest'        => 'nullable|boolean',
        ]);
        $customerId = (int) $data['customer_id'];
        $tripId     = (int) $data['trip_id'];
        $customer   = Customer::findOrFail($customerId);

        $this->authorizeShipmentAccess($customerId, $tripId);
        if (!$this->isReadyToShip($customerId, $tripId)) {
            return $this->shipmentBack($customer, 'error', "{$customer->name} is not fully paid and verified yet, so it cannot be shipped.");
        }

        // Locked while we count, so two people logging a sending at the same moment
        // cannot together send more packages than the total.
        $result = DB::transaction(function () use ($customerId, $tripId, $data, $request, $customer) {
            $shipment = Shipment::where(['customer_id' => $customerId, 'trip_id' => $tripId])->lockForUpdate()->first();
            if (!$shipment || !$shipment->package_total) {
                return ['error', "Set the total number of packages for {$customer->name} first."];
            }

            $sent = $shipment->packagesSent();
            $left = $shipment->package_total - $sent;
            if ($left <= 0) {
                return ['error', "All {$shipment->package_total} package(s) of {$customer->name} are already sent."];
            }

            $packages = $request->boolean('rest') ? $left : ($data['packages'] ?? null);
            if ($packages === null) {
                return ['error', "Enter how many packages were sent to {$customer->name}."];
            }
            if ($packages > $left) {
                return ['error', "Only {$left} package(s) are left to send to {$customer->name} (total {$shipment->package_total}, already sent {$sent})."];
            }

            $date = $data['shipped_on'] ?? now()->toDateString();
            $shipment->batches()->create(['packages' => $packages, 'shipped_on' => $date, 'created_by' => Auth::id()]);
            $shipment->update(['updated_by' => Auth::id()]);

            $left -= $packages;
            return ['success', "Saved for {$customer->name}: {$packages} package(s) sent on " . Carbon::parse($date)->format('d M Y')
                . ($left > 0 ? " — {$left} left to send." : ' — all packages are sent.')];
        });

        return $this->shipmentBack($customer, $result[0], $result[1]);
    }

    /** Change the number of packages or the date of one logged sending. */
    public function updateShipmentBatch(Request $request, ShipmentBatch $batch)
    {
        $data = $request->validate([
            'packages'   => 'required|integer|min:1|max:9999',
            'shipped_on' => 'required|date',
        ]);
        $shipment = $batch->shipment;
        $customer = Customer::findOrFail($shipment->customer_id);
        $this->authorizeShipmentAccess($shipment->customer_id, $shipment->trip_id);

        $others = (int) ShipmentBatch::where('shipment_id', $shipment->id)->where('id', '!=', $batch->id)->sum('packages');
        $room   = $shipment->package_total - $others;
        if ($data['packages'] > $room) {
            return $this->shipmentBack($customer, 'error', "That would be more than the total: at most {$room} package(s) can be in this sending (total {$shipment->package_total}, other sendings {$others}).");
        }

        $batch->update(['packages' => $data['packages'], 'shipped_on' => $data['shipped_on']]);
        $shipment->update(['updated_by' => Auth::id()]);

        return $this->shipmentBack($customer, 'success', "Updated for {$customer->name}: {$data['packages']} package(s) on " . Carbon::parse($data['shipped_on'])->format('d M Y') . '.');
    }

    /** Delete one logged sending (those packages go back to "left to send"). */
    public function destroyShipmentBatch(ShipmentBatch $batch)
    {
        $shipment = $batch->shipment;
        $customer = Customer::findOrFail($shipment->customer_id);
        $this->authorizeShipmentAccess($shipment->customer_id, $shipment->trip_id);

        $batch->delete();
        $shipment->update(['updated_by' => Auth::id()]);

        return $this->shipmentBack($customer, 'success', "Deleted a sending of {$customer->name} — those package(s) are back to 'left to send'.");
    }

    public function markPrinted(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'trip_id'     => 'required|exists:trips,id',
        ]);

        $orders = Order::where('customer_id', $data['customer_id'])
            ->where('trip_id', $data['trip_id'])->get();

        $allPrinted = $orders->isNotEmpty() && $orders->every(fn($o) => $o->invoice_printed_at !== null);

        \DB::transaction(function () use ($orders, $allPrinted) {
            foreach ($orders as $order) {
                $order->update([
                    'invoice_printed_at' => $allPrinted ? null : now(),
                    'invoice_printed_by' => $allPrinted ? null : Auth::id(),
                ]);
            }
        });

        return back()->with('success', $allPrinted ? 'Marked as NOT printed.' : 'Marked as printed — ready to pack.');
    }

    public function verifyBatch(Request $request, string $batchId)
    {
        if (!Auth::user()->hasPermission('payments.verify')) abort(403);

        $payments = Payment::where('batch_id', $batchId)
            ->whereNull('voided_at')
            ->where('verification_status', '!=', 'verified')
            ->get();

        if ($payments->isEmpty()) {
            return back()->with('error', 'No payments to verify in this batch.');
        }

        $total = 0;
        \DB::transaction(function () use ($payments, &$total) {
            foreach ($payments as $payment) {
                $payment->update([
                    'verification_status' => 'verified',
                    'verified_by'         => Auth::id(),
                    'verified_at'         => now(),
                    'dispute_note'        => null,
                ]);
                $total += $payment->amount;
            }
            Order::whereIn('id', $payments->pluck('order_id')->unique())->get(['id', 'customer_id', 'trip_id'])
                ->unique(fn ($o) => $o->customer_id . '-' . $o->trip_id)
                ->each(fn ($o) => $this->rebalanceAfterVerification($o));
        });

        return back()->with('success', $payments->count() . ' payment(s) totaling Rp ' . number_format($total, 0, ',', '.') . ' verified.');
    }

    public function dispute(Request $request, Payment $payment)
    {
        if (!Auth::user()->hasPermission('payments.verify')) abort(403);
        if ($payment->isVoided()) return back()->with('error', 'Cannot dispute a voided payment.');
        $request->validate(['dispute_note' => 'required|string|max:1000']);
        $payment->update([
            'verification_status' => 'disputed',
            'verified_by'         => Auth::id(),
            'verified_at'         => now(),
            'dispute_note'        => $request->dispute_note,
        ]);
        $this->rebalanceAfterVerification($payment->order);
        return back()->with('success', 'Payment marked as disputed.');
    }

    /**
     * Verifying or disputing a payment doesn't change an order's paid figure,
     * but it does change which money may be moved to another order or
     * refunded — so credit that was waiting on a verification can now move,
     * and credit moved on the strength of a payment that is now disputed is
     * pulled back.
     */
    private function rebalanceAfterVerification(?Order $order): void
    {
        if (!$order) return;
        app(\App\Services\CreditReallocationService::class)->reconcile($order->customer_id, $order->trip_id);
    }

    // Fix #1: delegate to Order::recalcPaymentStatus() — single source of truth.
    private function recalcOrderPayment(?Order $order, bool $reconcile = true): void
    {
        if (!$order) return;
        $order->recalcPaymentStatus();
        // Batch flows pass false and rebalance once AFTER every order in the
        // batch has been recalculated — rebalancing mid-loop would read the
        // stored paid figures of orders not yet recalculated.
        if ($reconcile) {
            app(\App\Services\CreditReallocationService::class)->reconcile($order->customer_id, $order->trip_id);
        }
    }
}