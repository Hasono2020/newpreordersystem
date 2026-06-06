<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
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
        $query = Order::with('customer', 'trip')->latest();
        if ($request->trip_id)          $query->where('trip_id', $request->trip_id);
        if ($request->payment_status)   $query->where('payment_status', $request->payment_status);
        if ($request->search) {
            $query->whereHas('customer', fn($q) => $q->where('name', 'like', '%'.$request->search.'%')
                                                       ->orWhere('phone', 'like', '%'.$request->search.'%'));
        }
        $orders        = $query->paginate(20)->withQueryString();
        $trips         = Trip::orderByDesc('id')->get();
        $selectedTrip  = $request->trip_id ? Trip::find($request->trip_id) : null;
        return view('orders.index', compact('orders', 'trips', 'selectedTrip'));
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

            // Fallback: use customer's default shipping area if none set on order
            if (!$shippingAreaId) {
                $customer       = \App\Models\Customer::find($request->customer_id);
                $shippingAreaId = $customer?->default_shipping_area_id;
            }

            $order = Order::create([
                'trip_id'          => $request->trip_id,
                'customer_id'      => $request->customer_id,
                'shipping_area_id' => $shippingAreaId,
                'notes'            => $request->notes,
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
        return view('orders.show', compact('order', 'shippingAreas'));
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
        ]);

        $shippingAreaId = $request->shipping_area_id ?: null;
        if (!$shippingAreaId) {
            $shippingAreaId = $order->customer->default_shipping_area_id;
        }

        $order->update([
            'shipping_area_id' => $shippingAreaId,
            'notes'            => $request->notes,
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

        // Recalculate deposit_paid from all non-refund payments
        $paid = $order->payments()->where('type', '!=', 'refund')->sum('amount')
              - $order->payments()->where('type', 'refund')->sum('amount');
        $status = $paid <= 0 ? 'unpaid'
            : ($paid >= $order->total_amount ? 'paid' : 'partial');
        $order->update(['deposit_paid' => max(0, $paid), 'payment_status' => $status]);

        // Auto-advance: when fully paid, move all 'pending' items → 'confirmed'
        $autoConfirmed = 0;
        if ($status === 'paid') {
            $autoConfirmed = $order->items()
                ->where('status', 'pending')
                ->update(['status' => 'confirmed']);
        }

        $msg = 'Payment recorded.';
        if ($status === 'paid')    $msg .= ' ✓ Order is now fully paid.';
        if ($autoConfirmed > 0)    $msg .= " {$autoConfirmed} pending item(s) automatically confirmed.";
        if ($status === 'partial') $msg .= ' Balance remaining: Rp ' . number_format($order->total_amount - $paid, 0, ',', '.');

        return back()->with('success', $msg);
    }

    public function invoice(Order $order)
    {
        $order->load(['customer', 'trip', 'shippingArea', 'items.product', 'items.variant', 'payments', 'createdBy']);
        return view('orders.invoice', compact('order'));
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
