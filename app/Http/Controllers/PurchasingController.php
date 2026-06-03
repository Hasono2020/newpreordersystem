<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchasingController extends Controller
{
    /**
     * Show purchasing overview for a trip — aggregate demand vs stock
     */
    public function index(Request $request)
    {
        $trips = Trip::whereIn('status', ['open', 'purchasing'])->orderByDesc('id')->get();
        $selectedTrip = $request->trip_id ? Trip::find($request->trip_id) : $trips->first();

        $demandData = [];
        if ($selectedTrip) {
            // Aggregate all confirmed/pending order items by variant
            $items = OrderItem::whereHas('order', fn($q) => $q->where('trip_id', $selectedTrip->id))
                ->whereNotIn('status', ['cancelled', 'sold_out'])
                ->with('product', 'variant')
                ->get()
                ->groupBy('product_variant_id');

            foreach ($items as $variantId => $group) {
                $first = $group->first();
                $demandData[] = [
                    'product' => $first->product,
                    'variant' => $first->variant,
                    'total_demanded' => $group->sum('quantity'),
                    'supplier_stock' => $first->variant?->supplier_stock ?? 0,
                    'remaining' => $first->variant ? $first->variant->remaining_stock : 0,
                ];
            }
        }

        $purchaseOrders = $selectedTrip
            ? PurchaseOrder::where('trip_id', $selectedTrip->id)->with('items.product', 'items.variant')->latest()->get()
            : collect();

        return view('purchasing.index', compact('trips', 'selectedTrip', 'demandData', 'purchaseOrders'));
    }

    /**
     * Create a purchase order from trip demand
     */
    public function store(Request $request)
    {
        $request->validate([
            'trip_id' => 'required|exists:trips,id',
            'supplier_name' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($request, &$po) {
            $po = PurchaseOrder::create([
                'trip_id' => $request->trip_id,
                'supplier_name' => $request->supplier_name,
                'created_by' => Auth::id(),
                'purchased_at' => now()->toDateString(),
            ]);

            $total = 0;
            foreach ($request->items as $item) {
                $lineTotal = $item['unit_cost'] * $item['quantity_ordered'];
                $po->items()->create([
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'quantity_ordered' => $item['quantity_ordered'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $lineTotal,
                ]);
                $total += $lineTotal;
            }
            $po->update(['total_amount' => $total, 'status' => 'submitted']);
        });

        return redirect()->route('purchasing.show', $po)->with('success', 'Purchase order created.');
    }

    public function show(PurchaseOrder $purchasing)
    {
        $purchasing->load(['items.product', 'items.variant', 'trip']);
        return view('purchasing.show', compact('purchasing'));
    }

    /**
     * Confirm arrival and run FIFO allocation
     */
    public function confirmArrival(Request $request, PurchaseOrder $purchasing)
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($request, $purchasing) {
            foreach ($request->items as $itemData) {
                $poItem = $purchasing->items()->find($itemData['id']);
                $poItem->update(['quantity_received' => $itemData['quantity_received']]);

                // Update supplier_stock on variant
                if ($poItem->product_variant_id) {
                    ProductVariant::where('id', $poItem->product_variant_id)
                        ->update(['supplier_stock' => $itemData['quantity_received']]);
                }

                // FIFO allocation: get all pending order items for this variant ordered by order creation time
                $pendingItems = OrderItem::where('product_variant_id', $poItem->product_variant_id)
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->whereHas('order', fn($q) => $q->where('trip_id', $purchasing->trip_id))
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->orderBy('orders.created_at', 'asc')
                    ->select('order_items.*')
                    ->get();

                $remaining = $itemData['quantity_received'];
                foreach ($pendingItems as $orderItem) {
                    if ($remaining <= 0) {
                        $orderItem->update(['status' => 'sold_out']);
                    } elseif ($orderItem->quantity <= $remaining) {
                        $remaining -= $orderItem->quantity;
                        $orderItem->update(['status' => 'arrived']);
                    } else {
                        // Partial — mark as sold_out (can be split if needed)
                        $orderItem->update(['status' => 'sold_out']);
                    }
                }
            }

            $purchasing->update(['status' => 'arrived']);
        });

        return back()->with('success', 'Arrival confirmed and stock allocated via FIFO.');
    }
}
