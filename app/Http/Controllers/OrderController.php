<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
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

        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        if ($request->customer_id) $query->where('customer_id', $request->customer_id);
        if ($request->payment_status) $query->where('payment_status', $request->payment_status);
        if ($request->search) {
            $query->where('order_number', 'like', '%'.$request->search.'%')
                  ->orWhereHas('customer', fn($q) => $q->where('name', 'like', '%'.$request->search.'%'));
        }

        $orders = $query->paginate(20)->withQueryString();
        $trips = Trip::orderByDesc('id')->get();
        return view('orders.index', compact('orders', 'trips'));
    }

    public function create(Request $request)
    {
        $trips = Trip::whereIn('status', ['open'])->orderByDesc('id')->get();
        $customers = Customer::orderBy('name')->get();
        $selectedTrip = $request->trip_id ? Trip::with('products.variants')->find($request->trip_id) : null;
        return view('orders.create', compact('trips', 'customers', 'selectedTrip'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'trip_id' => 'required|exists:trips,id',
            'customer_id' => 'required|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'shipping_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($request, &$order) {
            $order = Order::create([
                'trip_id' => $request->trip_id,
                'customer_id' => $request->customer_id,
                'created_by' => Auth::id(),
                'shipping_fee' => $request->shipping_fee ?? 0,
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $item) {
                $lineTotal = $item['unit_price'] * $item['quantity'];
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                    'status' => 'pending',
                ]);
            }

            // Recalculate with promos
            $calc = $this->promoService->recalculate($order);
            $order->update([
                'subtotal' => $calc['subtotal'],
                'discount_amount' => $calc['discount_amount'],
                'shipping_discount' => $calc['shipping_discount'],
                'total_amount' => $calc['total_amount'],
            ]);
        });

        return redirect()->route('orders.show', $order)->with('success', 'Order created successfully.');
    }

    public function show(Order $order)
    {
        $order->load(['customer', 'trip', 'items.product', 'items.variant', 'payments.recordedBy', 'createdBy']);
        return view('orders.show', compact('order'));
    }

    public function edit(Order $order)
    {
        $order->load(['items.product.variants', 'items.variant', 'customer', 'trip.products.variants']);
        $customers = Customer::orderBy('name')->get();
        return view('orders.edit', compact('order', 'customers'));
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'shipping_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $order->update([
            'shipping_fee' => $request->shipping_fee ?? 0,
            'notes' => $request->notes,
        ]);

        $calc = $this->promoService->recalculate($order);
        $order->update([
            'subtotal' => $calc['subtotal'],
            'discount_amount' => $calc['discount_amount'],
            'shipping_discount' => $calc['shipping_discount'],
            'total_amount' => $calc['total_amount'],
        ]);

        return redirect()->route('orders.show', $order)->with('success', 'Order updated.');
    }

    public function destroy(Order $order)
    {
        $order->delete();
        return redirect()->route('orders.index')->with('success', 'Order deleted.');
    }

    // Add item to existing order
    public function addItem(Request $request, Order $order)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
        ]);

        $order->items()->create([
            'product_id' => $request->product_id,
            'product_variant_id' => $request->product_variant_id,
            'quantity' => $request->quantity,
            'unit_price' => $request->unit_price,
            'line_total' => $request->unit_price * $request->quantity,
            'status' => 'pending',
        ]);

        $calc = $this->promoService->recalculate($order);
        $order->update([
            'subtotal' => $calc['subtotal'],
            'discount_amount' => $calc['discount_amount'],
            'shipping_discount' => $calc['shipping_discount'],
            'total_amount' => $calc['total_amount'],
        ]);

        return back()->with('success', 'Item added.');
    }

    // Update item status
    public function updateItemStatus(Request $request, Order $order, OrderItem $item)
    {
        $request->validate(['status' => 'required|in:pending,confirmed,purchased,arrived,sold_out,cancelled']);
        $item->update(['status' => $request->status]);

        // Recalc if item was cancelled/sold_out
        $calc = $this->promoService->recalculate($order);
        $order->update([
            'subtotal' => $calc['subtotal'],
            'discount_amount' => $calc['discount_amount'],
            'shipping_discount' => $calc['shipping_discount'],
            'total_amount' => $calc['total_amount'],
        ]);

        return back()->with('success', 'Item status updated.');
    }

    public function removeItem(Order $order, OrderItem $item)
    {
        $item->delete();
        $calc = $this->promoService->recalculate($order);
        $order->update([
            'subtotal' => $calc['subtotal'],
            'discount_amount' => $calc['discount_amount'],
            'shipping_discount' => $calc['shipping_discount'],
            'total_amount' => $calc['total_amount'],
        ]);
        return back()->with('success', 'Item removed.');
    }

    // Record payment
    public function addPayment(Request $request, Order $order)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'type' => 'required|in:deposit,partial,full,refund',
            'method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:100',
            'paid_at' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $order->payments()->create([
            'amount' => $request->amount,
            'type' => $request->type,
            'method' => $request->method,
            'reference' => $request->reference,
            'paid_at' => $request->paid_at,
            'notes' => $request->notes,
            'recorded_by' => Auth::id(),
        ]);

        // Recalculate total paid
        $totalPaid = $order->payments()->where('type', '!=', 'refund')->sum('amount')
            - $order->payments()->where('type', 'refund')->sum('amount');

        $paymentStatus = 'unpaid';
        if ($totalPaid >= $order->total_amount) $paymentStatus = 'paid';
        elseif ($totalPaid > 0) $paymentStatus = 'partial';

        $order->update(['deposit_paid' => $totalPaid, 'payment_status' => $paymentStatus]);

        return back()->with('success', 'Payment recorded.');
    }

    // Get products for a trip (AJAX)
    public function tripProducts(Trip $trip)
    {
        $products = $trip->products()->where('status', 'active')->with('variants')->get();
        return response()->json($products);
    }
}
