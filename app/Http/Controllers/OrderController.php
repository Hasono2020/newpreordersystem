<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Services\PromoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(protected PromoService $promoService) {}

    public function index(Request $request)
    {
        $perPage = in_array((int)$request->per_page, [20, 50, 100, 200]) ? (int)$request->per_page : 20;
        $query   = Order::with('customer', 'trip', 'createdBy')->latest();
        // Staff with own_data=true only see orders they created
        if (Auth::user()->isOwnDataOnly()) {
            $query->where('created_by', Auth::id());
        }
        if ($request->trip_id)        $query->where('trip_id', $request->trip_id);
        if ($request->payment_status) $query->where('payment_status', $request->payment_status);
        if ($request->created_by)     $query->where('created_by', $request->created_by);
        // Filter by shipping-area presence on the order itself
        if ($request->shipping_area === 'none') {
            $query->whereNull('shipping_area_id');
        } elseif ($request->shipping_area === 'set') {
            $query->whereNotNull('shipping_area_id');
        }
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', '%'.$search.'%')
                  ->orWhereHas('customer', fn($c) => $c->where('name', 'like', '%'.$search.'%')
                                                       ->orWhere('phone', 'like', '%'.$search.'%'));
            });
        }
        $orders       = $query->paginate($perPage)->withQueryString();
        $trips        = Trip::orderByDesc('id')->get();
        $selectedTrip = $request->trip_id ? Trip::find($request->trip_id) : null;
        $staffList = \App\Models\User::where('is_active', true)->orderBy('name')->get(['id','name','role']);

        // Count of orders with no shipping area (respecting own-data scope + current trip filter)
        $noAreaQuery = Order::whereNull('shipping_area_id');
        if (Auth::user()->isOwnDataOnly()) $noAreaQuery->where('created_by', Auth::id());
        if ($request->trip_id) $noAreaQuery->where('trip_id', $request->trip_id);
        $noAreaCount = $noAreaQuery->count();

        return view('orders.index', compact('orders', 'trips', 'selectedTrip', 'perPage', 'staffList', 'noAreaCount'));
    }

    public function create(Request $request)
    {
        if (!Auth::user()->hasPermission('orders.create')) abort(403);
        $trips         = Trip::where('status', 'open')->orderByDesc('id')->get();
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();
        $csAgents      = \App\Models\CsAgent::where('is_active', true)->orderBy('name')->get();
        $selectedTrip  = $request->trip_id ? Trip::find($request->trip_id) : null;
        return view('orders.create', compact('trips', 'shippingAreas', 'csAgents', 'selectedTrip'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'trip_id'          => 'required|exists:trips,id',
            'customer_id'      => 'required|exists:customers,id',
            'shipping_area_id' => 'nullable|exists:shipping_areas,id',
            'cs_agent_id'      => 'required|exists:cs_agents,id',
            'notes'            => 'nullable|string',
            'ordered_at'       => 'nullable|date',
            'items'                          => 'required|array|min:1',
            'items.*.product_id'             => 'required|exists:products,id',
            'items.*.product_variant_id'     => 'nullable|exists:product_variants,id',
            'items.*.quantity'               => 'required|integer|min:1',
            'items.*.unit_price'             => 'required|numeric|min:0',
        ], [
            'cs_agent_id.required'        => 'Please select which Customer Service handled this order.',
            'items.required'              => 'Please add at least one product before creating the order.',
            'items.min'                   => 'Please add at least one product before creating the order.',
            'items.*.product_id.required' => 'Each item row must have a product selected.',
            'items.*.quantity.min'        => 'Quantity must be at least 1.',
        ]);

        // Block new orders if trip is order_closed or beyond
        $trip = Trip::findOrFail($request->trip_id);
        if (!in_array($trip->status, ['open'])) {
            return back()->with('error', "Orders are closed for this trip (status: {$trip->status}).");
        }

        $order = \DB::transaction(function () use ($request) {
            $shippingAreaId = $request->shipping_area_id ?: null;
            if (!$shippingAreaId) {
                $customer       = \App\Models\Customer::find($request->customer_id);
                $shippingAreaId = $customer?->default_shipping_area_id;
            }

            $order = Order::create([
                'trip_id'          => $request->trip_id,
                'customer_id'      => $request->customer_id,
                'shipping_area_id' => $shippingAreaId,
                'cs_agent_id'      => $request->cs_agent_id ?: null,
                'notes'            => $request->notes,
                'ordered_at'       => $request->ordered_at ?: now(),
                'created_by'       => Auth::id(),
            ]);

            // Merge duplicate product+variant combinations before saving
            $mergedItems = [];
            foreach ($request->items as $itemData) {
                if (empty($itemData['product_id'])) continue;

                // Validate variant belongs to product
                $variantId = $itemData['product_variant_id'] ?? null;
                if ($variantId) {
                    $variant = \App\Models\ProductVariant::find($variantId);
                    if (!$variant || $variant->product_id != $itemData['product_id']) {
                        $variantId = null;
                    }
                }

                // If product has variants but none was selected — reject.
                // Throwing here (instead of returning a response) ensures the transaction
                // rolls back cleanly and the caller gets a proper validation error,
                // rather than a response object being mistaken for the Order model below.
                if (!$variantId) {
                    $hasVariants = \App\Models\ProductVariant::where('product_id', $itemData['product_id'])->exists();
                    if ($hasVariants) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => 'One or more items are missing a variant selection. Please pick a variant for each product.',
                        ]);
                    }
                }

                $key = $itemData['product_id'] . '_' . ($variantId ?? '0');
                if (isset($mergedItems[$key])) {
                    // Same product+variant already added — merge quantities
                    $mergedItems[$key]['quantity'] += max(1, (int)$itemData['quantity']);
                } else {
                    $mergedItems[$key] = [
                        'product_id'         => $itemData['product_id'],
                        'product_variant_id' => $variantId,
                        'quantity'           => max(1, (int)$itemData['quantity']),
                        'unit_price'         => (float)$itemData['unit_price'],
                    ];
                }
            }

            // Save merged items
            foreach ($mergedItems as $item) {
                $price = $item['unit_price'];
                $qty   = $item['quantity'];
                $order->items()->create([
                    'product_id'         => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity'           => $qty,
                    'unit_price'         => $price,
                    'line_total'         => $price * $qty,
                    'status'             => 'pending',
                ]);
            }

            // Recalculate totals with promo + shipping
            $calc = $this->promoService->recalculate($order->fresh());
            $order->update([
                'subtotal'             => $calc['subtotal'],
                'discount_amount'      => $calc['discount_amount'],
                'shipping_fee'         => $calc['shipping_fee'],
                'shipping_discount'    => $calc['shipping_discount'],
                'shipping_weight_gram' => $calc['shipping_weight_gram'],
                'shipping_kg_charged'  => $calc['shipping_kg_charged'],
                'total_amount'         => $calc['total_amount'],
            ]);

            return $order;
        });

        // Combine shipping across all this customer's orders in the trip (charge once)
        $this->promoService->recalcCustomerShipping($order->customer_id, $order->trip_id);

        return redirect()->route('orders.show', $order)->with('success', 'Order created successfully.');
    }

    public function show(Order $order)
    {
        $order->load(['customer', 'trip', 'shippingArea', 'items.product', 'items.variant', 'payments', 'createdBy', 'csAgent']);
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();

        // Determine which promo applies (for display only — no DB writes).
        // Uses combined items across ALL the customer's orders in this
        // trip — the same set recalcCustomerShipping() uses for the real
        // total — so this banner can't disagree with what was actually
        // charged. Checking only $order->items here previously made a
        // customer who genuinely qualified via their combined orders show
        // "No promo applied — needs N more items" on an order that, in
        // reality, already had the promo correctly applied to its total.
        $activeItems  = $this->promoService->combinedActiveItems($order->customer_id, $order->trip_id);
        $appliedPromo = $this->promoService->getBestPromo(
            $order->customer->type,
            $order->trip_id,
            $activeItems
        );

        // Next promo tier hint (moved out of the Blade view to avoid a query in the template)
        $combinedItemCount = $activeItems->sum('quantity');
        $nextRule = null;
        if (!$appliedPromo) {
            $itemCount = $combinedItemCount;
            $nextRule  = \App\Models\PromoRule::where('is_active', true)
                ->where(fn($q) => $q->where('trip_id', $order->trip_id)->orWhereNull('trip_id'))
                ->where('min_items', '>', $itemCount)
                ->orderBy('min_items')->first();
        }

        return view('orders.show', compact('order', 'shippingAreas', 'appliedPromo', 'nextRule', 'combinedItemCount'));
    }

    /**
     * Can the current user write this order?
     * Admin: any. Staff: own only. Other roles: any if permission granted.
     */
    private function canWriteOrder(Order $order, string $perm = 'orders.edit'): bool
    {
        /** @var \App\Models\User $user */
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user) return false;
        if ($user->isAdmin()) return true;
        if (!$user->hasPermission($perm)) return false;
        if ($user->role === 'staff' && $order->created_by !== $user->id) return false;
        return true;
    }

    public function edit(Order $order)
    {
        if (!$this->canWriteOrder($order, 'orders.edit')) abort(403, "You don't have permission to edit this order.");
        $order->load([
            'customer',
            'trip.products.variants',
            'shippingArea',
            'items.product',
            'items.variant',
            'payments',
            'createdBy',
        ]);
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();
        $csAgents      = \App\Models\CsAgent::where('is_active', true)->orderBy('name')->get();
        return view('orders.edit', compact('order', 'shippingAreas', 'csAgents'));
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'shipping_area_id' => 'nullable|exists:shipping_areas,id',
            'cs_agent_id'      => 'nullable|exists:cs_agents,id',
            'notes'            => 'nullable|string',
            'ordered_at'       => 'nullable|date',
        ]);

        $shippingAreaId = $request->shipping_area_id ?: null;
        if (!$shippingAreaId) {
            $shippingAreaId = $order->customer->default_shipping_area_id;
        }

        // Preserve the existing CS agent if the form didn't submit one
        // (e.g. the Shipping & Recalculate panel, which doesn't include it)
        $csAgentId = $request->filled('cs_agent_id') ? $request->cs_agent_id : $order->cs_agent_id;

        // Capture before-state for audit log
        $before = [
            'shipping_area_id' => $order->shipping_area_id,
            'cs_agent_id'      => $order->cs_agent_id,
            'notes'            => $order->notes,
            'ordered_at'       => optional($order->ordered_at)->toDateTimeString(),
            'total_amount'     => $order->total_amount,
        ];

        $order->update([
            'shipping_area_id' => $shippingAreaId,
            'cs_agent_id'      => $csAgentId,
            'notes'            => $request->notes,
            'ordered_at'       => $request->ordered_at ?: $order->ordered_at,
        ]);

        // Recalculate combined shipping across all the customer's orders in this trip
        $this->promoService->recalcCustomerShipping($order->customer_id, $order->trip_id);

        // Audit log — record only the fields that actually changed (before/after)
        $order->refresh();
        $after = [
            'shipping_area_id' => $order->shipping_area_id,
            'cs_agent_id'      => $order->cs_agent_id,
            'notes'            => $order->notes,
            'ordered_at'       => optional($order->ordered_at)->toDateTimeString(),
            'total_amount'     => $order->total_amount,
        ];
        $changes = [];
        foreach ($after as $field => $newVal) {
            if (($before[$field] ?? null) != $newVal) {
                $changes[$field] = ['old' => $before[$field] ?? null, 'new' => $newVal];
            }
        }
        if ($changes) {
            \App\Models\ActivityLog::record(
                'order.updated',
                "Edited order {$order->order_number} ({$order->customer->name})",
                'order',
                $order->id,
                $changes
            );
        }

        return redirect()->route('orders.show', $order)->with('success', 'Order updated.');
    }

    public function bulkDestroy(Request $request)
    {
        $this->adminOnly('bulk delete orders');
        $request->validate([
            'action'    => 'required|in:selected,unpaid,trip',
            'order_ids' => 'required_if:action,selected|array',
        ]);

        $query = Order::query();
        if ($request->action === 'selected') {
            $query->whereIn('id', $request->order_ids ?? []);
        } elseif ($request->action === 'unpaid') {
            $query->where('payment_status', 'unpaid');
            if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        } elseif ($request->action === 'trip' && $request->trip_id) {
            $query->where('trip_id', $request->trip_id);
        }

        $deleted = 0;
        $deletedNumbers = [];
        $affectedPairs = collect();

        \DB::transaction(function () use ($query, &$deleted, &$deletedNumbers, &$affectedPairs) {
            $orders = $query->get(['id', 'order_number', 'customer_id', 'trip_id']);
            if ($orders->isEmpty()) return;
            $orderIds = $orders->pluck('id');
            $deletedNumbers = $orders->pluck('order_number')->all();

            // Collect unique customer+trip pairs BEFORE deleting so we can
            // recalculate shipping for their remaining orders afterward.
            $affectedPairs = $orders
                ->map(fn($o) => ['customer_id' => $o->customer_id, 'trip_id' => $o->trip_id])
                ->unique(fn($p) => $p['customer_id'] . '_' . $p['trip_id'])
                ->values();

            // Delete in correct FK order: payments → order_items → orders
            // (purchase_order_items has no FK to order_items)
            \DB::table('payments')->whereIn('order_id', $orderIds)->delete();
            \DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            \DB::table('orders')->whereIn('id', $orderIds)->delete();
            $deleted = $orderIds->count();
        });

        // Recalculate combined shipping for every affected customer+trip.
        // If a customer had the anchor order deleted, their remaining orders
        // need a new anchor assigned with the correct shipping fee.
        // recalcCustomerShipping() returns early safely if no orders remain.
        foreach ($affectedPairs as $pair) {
            $this->promoService->recalcCustomerShipping($pair['customer_id'], $pair['trip_id']);
        }

        if ($deleted > 0) {
            $sample = implode(', ', array_slice($deletedNumbers, 0, 10));
            if (count($deletedNumbers) > 10) $sample .= ', …';
            \App\Models\ActivityLog::record(
                'order.bulk_deleted',
                "Bulk-deleted {$deleted} order(s) [{$request->action}]: {$sample}",
                'order',
                null
            );
        }

        return redirect()->route('orders.index', request()->only('trip_id', 'payment_status', 'search'))
            ->with('success', "Deleted {$deleted} order(s).");
    }

    public function destroy(Order $order)
    {
        $this->adminOnly('delete orders');
        $customerId   = $order->customer_id;
        $tripId       = $order->trip_id;
        $orderNumber  = $order->order_number;
        $customerName = $order->customer->name ?? '';
        $order->delete();
        // Re-combine shipping for the customer's remaining orders (anchor may have changed)
        $this->promoService->recalcCustomerShipping($customerId, $tripId);

        \App\Models\ActivityLog::record(
            'order.deleted',
            "Deleted order {$orderNumber} ({$customerName})",
            'order',
            $order->id
        );

        return redirect(session('list_url.orders', route('orders.index')))->with('success', 'Order deleted.');
    }

    // ── Item Management ─────────────────────────────────────────────

    public function updateItem(Request $request, Order $order, OrderItem $item)
    {
        // Bug 2 fix: ensure item belongs to this order
        abort_if($item->order_id !== $order->id, 404, 'Item does not belong to this order.');

        $request->validate([
            'quantity'   => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
        ]);

        $item->update([
            'quantity'   => $request->quantity,
            'unit_price' => $request->unit_price,
            'line_total' => $request->unit_price * $request->quantity,
        ]);

        $this->_recalcAndSave($order);
        return back()->with('success', 'Item updated.');
    }

    public function addItem(Request $request, Order $order)
    {
        $request->validate([
            'product_id'         => 'required|exists:products,id',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'quantity'           => 'required|integer|min:1',
            'unit_price'         => 'required|numeric|min:0',
        ]);

        // Verify variant belongs to the selected product
        if ($request->product_variant_id) {
            $variant = ProductVariant::findOrFail($request->product_variant_id);
            abort_if(
                $variant->product_id != $request->product_id,
                422,
                'Selected variant does not belong to this product.'
            );
        }

        // If the product HAS variants, one must be chosen — a null variant would make
        // stock allocation / purchasing / fulfillment ambiguous. Products with no
        // variants at all may legitimately have a null variant.
        $productHasVariants = ProductVariant::where('product_id', $request->product_id)->exists();
        if ($productHasVariants && !$request->product_variant_id) {
            return back()->with('error', 'This product has color/size variants — please select one before adding.');
        }

        // Check if same product+variant already exists in this order
        $existing = $order->items()
            ->where('product_id', $request->product_id)
            ->where('product_variant_id', $request->product_variant_id ?: null)
            ->whereNotIn('status', ['cancelled', 'sold_out'])
            ->first();

        if ($existing) {
            $newQty = $existing->quantity + $request->quantity;
            $existing->update([
                'quantity'   => $newQty,
                'line_total' => $existing->unit_price * $newQty,
            ]);
        } else {
            $order->items()->create([
                'product_id'         => $request->product_id,
                'product_variant_id' => $request->product_variant_id,
                'quantity'           => $request->quantity,
                'unit_price'         => $request->unit_price,
                'line_total'         => $request->unit_price * $request->quantity,
                'status'             => 'pending',
            ]);
        }

        $this->_recalcAndSave($order);
        return back()->with('success', 'Item added.');
    }

    public function updateItemStatus(Request $request, Order $order, OrderItem $item)
    {
        // Bug 2 fix: ensure item belongs to this order
        abort_if($item->order_id !== $order->id, 404, 'Item does not belong to this order.');

        $request->validate(['status' => 'required|in:pending,confirmed,purchased,arrived,sold_out,cancelled']);
        $item->update(['status' => $request->status]);
        $this->_recalcAndSave($order);
        return back()->with('success', 'Item status updated.');
    }

    public function removeItem(Order $order, OrderItem $item)
    {
        $this->adminOnly('remove order items');
        abort_if($item->order_id !== $order->id, 404, 'Item does not belong to this order.');

        $item->delete();
        $this->_recalcAndSave($order);
        return back()->with('success', 'Item removed.');
    }

    public function addPayment(Request $request, Order $order)
    {
        $request->validate([
            'amount'    => 'required|numeric|min:0',
            'type'      => 'required|in:deposit,partial,full,refund',
            'method'    => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:100',
            'paid_at'   => 'required|date',
            'notes'     => 'nullable|string',
        ]);

        \DB::transaction(function () use ($request, $order) {
            $order->payments()->create([
                'amount'      => $request->amount,
                'type'        => $request->type,
                'method'      => $request->method ?: 'Transfer',
                'reference'   => $request->reference,
                'paid_at'     => $request->paid_at,
                'notes'       => $request->notes,
                'recorded_by' => Auth::id(),
            ]);

            $this->recalcOrderPayment($order);
        });
        $order->refresh();

        \App\Models\ActivityLog::record(
            'payment.recorded',
            'Recorded Rp ' . number_format($request->amount, 0, ',', '.') . " ({$request->type}) on {$order->order_number} ({$order->customer->name})",
            'order',
            $order->id
        );

        $msg = 'Payment recorded.';
        if ($order->payment_status === 'paid')    $msg .= ' ✓ Order is now fully paid.';
        if ($order->payment_status === 'partial') $msg .= ' Balance remaining: Rp ' . number_format($order->total_amount - $order->deposit_paid, 0, ',', '.');

        return back()->with('success', $msg);
    }

    public function voidPayment(Request $request, \App\Models\Payment $payment)
    {
        $request->validate(['void_reason' => 'required|string|max:500']);

        if ($payment->isVoided()) {
            return back()->with('error', 'This payment has already been voided.');
        }

        $order = $payment->order;
        \DB::transaction(function () use ($request, $payment, $order) {
            $payment->update([
                'voided_at'   => now(),
                'voided_by'   => Auth::id(),
                'void_reason' => $request->void_reason,
            ]);

            $this->recalcOrderPayment($order);
        });
        $order->refresh();

        \App\Models\ActivityLog::record(
            'payment.voided',
            'Voided Rp ' . number_format($payment->amount, 0, ',', '.') . " payment on {$order->order_number} ({$order->customer->name}) — reason: {$request->void_reason}",
            'order',
            $order->id
        );

        return back()->with('success',
            'Payment of Rp ' . number_format($payment->amount, 0, ',', '.') . ' voided. ' .
            'New balance due: Rp ' . number_format($order->total_amount - $order->deposit_paid, 0, ',', '.')
        );
    }

    // Fix #1: delegate to Order::recalcPaymentStatus() — single source of truth.
    private function recalcOrderPayment(Order $order): void
    {
        $order->recalcPaymentStatus();
    }

    public function invoice(Order $order)
    {
        $order->load(['customer', 'trip', 'shippingArea', 'items.product', 'items.variant', 'payments.recordedBy', 'payments.voidedBy', 'createdBy']);
        return view('orders.invoice', compact('order'));
    }

    /**
     * Combined invoice — merges all selected orders for one customer
     * into a single printable document.
     * URL: /customers/{customer}/combined-invoice?order_ids[]=1&order_ids[]=2&trip_id=3
     */
    public function combinedInvoice(Request $request, Customer $customer)
    {
        $tripId   = $request->trip_id;
        $orderIds = $request->order_ids ?? [];

        $query = Order::with(['items.product', 'items.variant', 'payments', 'trip', 'shippingArea'])
            ->where('customer_id', $customer->id);

        if ($tripId)            $query->where('trip_id', $tripId);
        if (!empty($orderIds))  $query->whereIn('id', $orderIds);

        // Fix #8: single with() call — no duplicate eager loads
        $orders = $query->orderBy('ordered_at')->get();

        if ($orders->isEmpty()) {
            return back()->with('error', 'No orders found for this customer.');
        }

        $customer->load('defaultShippingArea');

        // ── Combined promo & shipping calculation ────────────────────────
        // Collect all active items across all orders
        $allActiveItems = $orders->flatMap(fn($o) =>
            $o->items->whereNotIn('status', ['cancelled', 'sold_out'])
        );

        // Use first available shipping area across all orders (or customer default)
        $shippingArea = $orders->first(fn($o) => $o->shippingArea)?->shippingArea
            ?? $customer->defaultShippingArea;

        // Combined weight and shipping — routed through the same
        // calcTotalWeightGram() helper recalcCustomerShipping() uses, so
        // this printed preview can't drift from what was actually charged
        // (e.g. by missing the cargo weight bump).
        $totalWeightGram  = $this->promoService->calcTotalWeightGram($allActiveItems, (bool) $customer->use_cargo);
        $combinedShipping = $shippingArea ? $shippingArea->calcShippingFee($totalWeightGram) : 0;
        $chargeableKg     = \App\Models\ShippingArea::calcChargeableKg($totalWeightGram);

        // Fix #4: use constructor-injected promoService (warm cache, consistent instance)
        $combinedPromo = $tripId
            ? $this->promoService->getBestPromo($customer->type, $tripId, $allActiveItems)
            : null;

        $combinedDiscount        = $combinedPromo ? $combinedPromo['discount'] : 0;
        $combinedShipSubsidy     = $combinedPromo ? $combinedPromo['max_shipping_subsidy'] : 0;
        $combinedShipDiscount    = min($combinedShipping, $combinedShipSubsidy);

        return view('orders.combined-invoice', compact(
            'customer', 'orders', 'tripId',
            'shippingArea', 'totalWeightGram', 'chargeableKg',
            'combinedShipping', 'combinedDiscount', 'combinedShipDiscount',
            'combinedPromo', 'allActiveItems'
        ));
    }

    // ── AJAX: trip products ──────────────────────────────────────────

    public function tripProducts(Trip $trip)
    {
        $products = $trip->products()->with('variants')->get()->map(fn($p) => [
            'id'       => $p->id,
            'name'     => $p->product_code,
            'code'     => $p->product_code,
            'price'    => $p->price,
            'weight'   => $p->weight_gram,
            'variants' => $p->variants->map(fn($v) => [
                'id'    => $v->id,
                'label' => $v->label,
                'color' => $v->color,
                'size'  => $v->size,
                'price' => $v->final_price,
            ]),
        ]);
        return response()->json($products);
    }

    // ── AJAX: customer search ──────────────────────────────────────

    public function searchCustomers(Request $request)
    {
        $q = $request->q ?? '';
        $customers = Customer::where('name', 'like', "%{$q}%")
            ->orWhere('phone', 'like', "%{$q}%")
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone', 'type', 'address', 'default_shipping_area_id', 'use_cargo']);
        return response()->json($customers);
    }

    // ── AJAX: quick-create customer ────────────────────────────────

    public function quickCreateCustomer(Request $request)
    {
        $data = $request->validate([
            'name'                     => 'required|string|max:255',
            'phone'                    => 'nullable|string|max:50',
            'type'                     => 'required|in:customer,reseller,selected_customer',
            'address'                  => 'nullable|string|max:500',
            'default_shipping_area_id' => 'nullable|exists:shipping_areas,id',
        ]);

        // Normalize and check phone duplicate
        if (!empty($data['phone'])) {
            $normalized = Customer::normalizePhone($data['phone']);
            $existing = Customer::where('phone', $normalized)->first();
            if ($existing) {
                return response()->json([
                    'duplicate' => true,
                    'customer'  => $existing,
                    'message'   => "Phone {$normalized} already belongs to customer: {$existing->name}",
                ]);
            }
        }

        $customer = Customer::create($data);
        $customer->load('defaultShippingArea');
        return response()->json(['duplicate' => false, 'customer' => $customer]);
    }

    // ── AJAX: shipping area list ───────────────────────────────────

    public function calcShipping(Request $request)
    {
        $area = ShippingArea::find($request->area_id);
        if (!$area) return response()->json(['fee' => 0]);
        $grams = (int) $request->grams;
        return response()->json(['fee' => $area->calcShippingFee($grams)]);
    }

    // ── Private helpers ────────────────────────────────────────────

    private function _recalcAndSave(Order $order): void
    {
        // Recalc combined shipping across all the customer's orders in this trip
        // (charges shipping once on the oldest order, zeroes the rest).
        $this->promoService->recalcCustomerShipping($order->customer_id, $order->trip_id);
    }
}