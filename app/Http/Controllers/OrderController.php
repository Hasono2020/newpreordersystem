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
        $query   = Order::with('customer', 'trip')->latest();
        if ($request->trip_id)        $query->where('trip_id', $request->trip_id);
        if ($request->payment_status) $query->where('payment_status', $request->payment_status);
        if ($request->search) {
            $query->whereHas('customer', fn($q) => $q->where('name', 'like', '%'.$request->search.'%')
                                                       ->orWhere('phone', 'like', '%'.$request->search.'%'));
        }
        $orders       = $query->paginate($perPage)->withQueryString();
        $trips        = Trip::orderByDesc('id')->get();
        $selectedTrip = $request->trip_id ? Trip::find($request->trip_id) : null;
        return view('orders.index', compact('orders', 'trips', 'selectedTrip', 'perPage'));
    }

    public function create(Request $request)
    {
        $trips         = Trip::where('status', 'open')->orderByDesc('id')->get();
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();
        $selectedTrip  = $request->trip_id ? Trip::find($request->trip_id) : null;
        return view('orders.create', compact('trips', 'shippingAreas', 'selectedTrip'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'trip_id'          => 'required|exists:trips,id',
            'customer_id'      => 'required|exists:customers,id',
            'shipping_area_id' => 'nullable|exists:shipping_areas,id',
            'notes'            => 'nullable|string',
            'ordered_at'       => 'nullable|date',
            'items'                          => 'required|array|min:1',
            'items.*.product_id'             => 'required|exists:products,id',
            'items.*.product_variant_id'     => 'nullable|exists:product_variants,id',
            'items.*.quantity'               => 'required|integer|min:1',
            'items.*.unit_price'             => 'required|numeric|min:0',
        ], [
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

        return redirect()->route('orders.show', $order)->with('success', 'Order created successfully.');
    }

    public function show(Order $order)
    {
        $order->load(['customer', 'trip', 'shippingArea', 'items.product', 'items.variant', 'payments', 'createdBy']);
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();

        // Determine which promo applies (for display only — no DB writes)
        $promoSvc   = app(\App\Services\PromoService::class);
        $activeItems = $order->items->whereNotIn('status', ['cancelled', 'sold_out']);
        $appliedPromo = $promoSvc->getBestPromo(
            $order->customer->type,
            $order->trip_id,
            $activeItems
        );

        return view('orders.show', compact('order', 'shippingAreas', 'appliedPromo'));
    }

    public function edit(Order $order)
    {
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
        return view('orders.edit', compact('order', 'shippingAreas'));
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'shipping_area_id' => 'nullable|exists:shipping_areas,id',
            'notes'            => 'nullable|string',
            'ordered_at'       => 'nullable|date',
        ]);

        $shippingAreaId = $request->shipping_area_id ?: null;
        if (!$shippingAreaId) {
            $shippingAreaId = $order->customer->default_shipping_area_id;
        }

        $order->update([
            'shipping_area_id' => $shippingAreaId,
            'notes'            => $request->notes,
            'ordered_at'       => $request->ordered_at ?: $order->ordered_at,
        ]);

        $calc = $this->promoService->recalculate($order);
        $order->update([
            'subtotal'             => $calc['subtotal'],
            'discount_amount'      => $calc['discount_amount'],
            'shipping_fee'         => $calc['shipping_fee'],
            'shipping_discount'    => $calc['shipping_discount'],
            'shipping_weight_gram' => $calc['shipping_weight_gram'],
            'shipping_kg_charged'  => $calc['shipping_kg_charged'],
            'total_amount'         => $calc['total_amount'],
        ]);

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
        \DB::transaction(function () use ($query, &$deleted) {
            $orderIds = $query->pluck('id');
            if ($orderIds->isEmpty()) return;

            // Delete in correct FK order: payments → order_items → orders
            // (purchase_order_items has no FK to order_items)
            \DB::table('payments')->whereIn('order_id', $orderIds)->delete();
            \DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            \DB::table('orders')->whereIn('id', $orderIds)->delete();
            $deleted = $orderIds->count();
        });

        return redirect()->route('orders.index', request()->only('trip_id', 'payment_status', 'search'))
            ->with('success', "Deleted {$deleted} order(s).");
    }

    public function destroy(Order $order)
    {
        $this->adminOnly('delete orders');
        $order->delete();
        return redirect()->route('orders.index')->with('success', 'Order deleted.');
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

        // Bug 1 fix: verify variant belongs to the selected product
        if ($request->product_variant_id) {
            $variant = ProductVariant::findOrFail($request->product_variant_id);
            abort_if(
                $variant->product_id != $request->product_id,
                422,
                'Selected variant does not belong to this product.'
            );
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

        $order->payments()->create([
            'amount'      => $request->amount,
            'type'        => $request->type,
            'method'      => $request->method,
            'reference'   => $request->reference,
            'paid_at'     => $request->paid_at,
            'notes'       => $request->notes,
            'recorded_by' => Auth::id(),
        ]);

        $this->recalcOrderPayment($order);
        $order->refresh();

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

        $payment->update([
            'voided_at'   => now(),
            'voided_by'   => Auth::id(),
            'void_reason' => $request->void_reason,
        ]);

        $order = $payment->order;
        $this->recalcOrderPayment($order);
        $order->refresh();

        return back()->with('success',
            'Payment of Rp ' . number_format($payment->amount, 0, ',', '.') . ' voided. ' .
            'New balance due: Rp ' . number_format($order->total_amount - $order->deposit_paid, 0, ',', '.')
        );
    }

    private function recalcOrderPayment(Order $order): void
    {
        $payments = $order->payments()->whereNull('voided_at')->get();
        $paid = $payments->where('type', '!=', 'refund')->sum('amount')
              - $payments->where('type', 'refund')->sum('amount');
        $status = $paid <= 0 ? 'unpaid'
            : ($paid >= $order->total_amount ? 'paid' : 'partial');
        // Only auto-confirm pending items when fully paid
        // Do NOT revert already-confirmed items when payment is voided
        if ($status === 'paid') {
            $order->items()->where('status', 'pending')->update(['status' => 'confirmed']);
        }
        $order->update(['deposit_paid' => max(0, $paid), 'payment_status' => $status]);
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

        // Combined weight and shipping
        $totalWeightGram  = $allActiveItems->sum(fn($i) => ($i->product->weight_gram ?? 0) * $i->quantity);
        $combinedShipping = $shippingArea ? $shippingArea->calcShippingFee($totalWeightGram) : 0;
        $chargeableKg     = \App\Models\ShippingArea::calcChargeableKg($totalWeightGram);

        // Combined promo based on all items together
        $promoSvc     = app(\App\Services\PromoService::class);
        $combinedPromo = $tripId
            ? $promoSvc->getBestPromo($customer->type, $tripId, $allActiveItems)
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
            'name'     => $p->name,
            'code'     => $p->product_code,
            'price'    => $p->price,
            'weight'   => $p->weight_gram,
            'variants' => $p->variants->map(fn($v) => [
                'id'    => $v->id,
                'label' => $v->label,
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
            ->get(['id', 'name', 'phone', 'type', 'address', 'default_shipping_area_id']);
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
    }
}