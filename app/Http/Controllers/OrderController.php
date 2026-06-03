<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
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

        if ($request->trip_id)       $query->where('trip_id', $request->trip_id);
        if ($request->customer_id)   $query->where('customer_id', $request->customer_id);
        if ($request->payment_status) $query->where('payment_status', $request->payment_status);
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('order_number', 'like', '%'.$request->search.'%')
                  ->orWhereHas('customer', fn($q2) => $q2->where('name', 'like', '%'.$request->search.'%'));
            });
        }

        $orders = $query->paginate(20)->withQueryString();
        $trips  = Trip::orderByDesc('id')->get();
        return view('orders.index', compact('orders', 'trips'));
    }

    public function create(Request $request)
    {
        $trips         = Trip::whereIn('status', ['open'])->orderByDesc('id')->get();
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();
        $selectedTrip  = $request->trip_id ? Trip::with('products.variants')->find($request->trip_id) : null;
        return view('orders.create', compact('trips', 'shippingAreas', 'selectedTrip'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'trip_id'          => 'required|exists:trips,id',
            'customer_id'      => 'required|exists:customers,id',
            'shipping_area_id' => 'nullable|exists:shipping_areas,id',
            'items'            => 'required|array|min:1',
            'items.*.product_id'         => 'required|exists:products,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity'           => 'required|integer|min:1',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'notes'            => 'nullable|string',
        ]);

        DB::transaction(function () use ($request, &$order) {
            $order = Order::create([
                'trip_id'          => $request->trip_id,
                'customer_id'      => $request->customer_id,
                'shipping_area_id' => $request->shipping_area_id ?: null,
                'created_by'       => Auth::id(),
                'notes'            => $request->notes,
            ]);

            foreach ($request->items as $item) {
                $lineTotal = $item['unit_price'] * $item['quantity'];
                $order->items()->create([
                    'product_id'         => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'quantity'           => $item['quantity'],
                    'unit_price'         => $item['unit_price'],
                    'line_total'         => $lineTotal,
                    'status'             => 'pending',
                ]);
            }

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
        });

        return redirect()->route('orders.show', $order)->with('success', 'Order created successfully.');
    }

    public function show(Order $order)
    {
        $order->load(['customer', 'trip', 'shippingArea', 'items.product', 'items.variant', 'payments.recordedBy', 'createdBy']);
        return view('orders.show', compact('order'));
    }

    public function edit(Order $order)
    {
        $order->load(['items.product.variants', 'items.variant', 'customer', 'trip.products.variants']);
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();
        return view('orders.edit', compact('order', 'shippingAreas'));
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'shipping_area_id' => 'nullable|exists:shipping_areas,id',
            'notes'            => 'nullable|string',
        ]);

        $order->update([
            'shipping_area_id' => $request->shipping_area_id ?: null,
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
        $order->delete();
        return redirect()->route('orders.index')->with('success', 'Order deleted.');
    }

    public function addItem(Request $request, Order $order)
    {
        $request->validate([
            'product_id'         => 'required|exists:products,id',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'quantity'           => 'required|integer|min:1',
            'unit_price'         => 'required|numeric|min:0',
        ]);

        $order->items()->create([
            'product_id'         => $request->product_id,
            'product_variant_id' => $request->product_variant_id,
            'quantity'           => $request->quantity,
            'unit_price'         => $request->unit_price,
            'line_total'         => $request->unit_price * $request->quantity,
            'status'             => 'pending',
        ]);

        $this->_recalcAndSave($order);
        return back()->with('success', 'Item added.');
    }

    public function updateItemStatus(Request $request, Order $order, OrderItem $item)
    {
        $request->validate(['status' => 'required|in:pending,confirmed,purchased,arrived,sold_out,cancelled']);
        $item->update(['status' => $request->status]);
        $this->_recalcAndSave($order);
        return back()->with('success', 'Item status updated.');
    }

    public function removeItem(Order $order, OrderItem $item)
    {
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

        $totalPaid     = $order->payments()->where('type', '!=', 'refund')->sum('amount')
                       - $order->payments()->where('type', 'refund')->sum('amount');
        $paymentStatus = 'unpaid';
        if ($totalPaid >= $order->total_amount) $paymentStatus = 'paid';
        elseif ($totalPaid > 0)                 $paymentStatus = 'partial';

        $order->update(['deposit_paid' => $totalPaid, 'payment_status' => $paymentStatus]);
        return back()->with('success', 'Payment recorded.');
    }

    // ── Quick-create customer via AJAX ──────────────────────────────
    public function quickCreateCustomer(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'type'  => 'required|in:customer,reseller,selected_customer',
        ]);
        $customer = Customer::create($data);
        return response()->json($customer);
    }

    // ── AJAX: search customers ──────────────────────────────────────
    public function searchCustomers(Request $request)
    {
        $q = $request->q ?? '';
        $customers = Customer::where('name', 'like', "%{$q}%")
            ->orWhere('phone', 'like', "%{$q}%")
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone', 'type']);
        return response()->json($customers);
    }

    // ── AJAX: products for a trip ───────────────────────────────────
    public function tripProducts(Trip $trip)
    {
        $products = $trip->products()
            ->where('status', 'active')
            ->with('variants')
            ->get()
            ->map(function ($p) {
                return [
                    'id'           => $p->id,
                    'name'         => $p->name,
                    'product_code' => $p->product_code,
                    'price'        => $p->price,
                    'weight_gram'  => $p->weight_gram,
                    'variants'     => $p->variants,
                ];
            });
        return response()->json($products);
    }

    // ── AJAX: calculate shipping fee ────────────────────────────────
    public function calcShipping(Request $request)
    {
        $areaId     = $request->shipping_area_id;
        $totalGrams = (int) $request->total_grams;

        if (!$areaId) return response()->json(['fee' => 0, 'kg' => 0]);

        $area = \App\Models\ShippingArea::find($areaId);
        if (!$area) return response()->json(['fee' => 0, 'kg' => 0]);

        $kg  = \App\Models\ShippingArea::calcChargeableKg($totalGrams);
        $fee = $area->calcShippingFee($totalGrams);

        return response()->json(['fee' => $fee, 'kg' => $kg, 'grams' => $totalGrams]);
    }

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
