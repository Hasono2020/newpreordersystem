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
    public function index(Request $request)
    {
        $trips        = Trip::whereIn('status', ['open', 'order_closed', 'purchasing'])->orderByDesc('id')->get();
        $selectedTrip = $request->trip_id ? Trip::find($request->trip_id) : $trips->first();

        // Group demand by supplier for multi-supplier support
        $demandBySupplier = [];
        if ($selectedTrip) {
            $allItems = OrderItem::whereHas('order', fn($q) => $q->where('trip_id', $selectedTrip->id))
                ->whereIn('status', ['pending', 'confirmed'])
                ->with('product.supplier', 'variant')
                ->get();

            // Get supplier IDs that already have an ACTIVE (not yet arrived) PO for this trip
            // If a PO is already 'arrived', the supplier can show again for new pending demand
            $supplierIdsWithActivePO = PurchaseOrder::where('trip_id', $selectedTrip->id)
                ->whereNotIn('status', ['cancelled', 'arrived'])
                ->whereNotNull('supplier_id')
                ->pluck('supplier_id')
                ->unique()
                ->toArray();

            // Check if a no-supplier active PO exists
            $hasNoSupplierActivePO = PurchaseOrder::where('trip_id', $selectedTrip->id)
                ->whereNotIn('status', ['cancelled', 'arrived'])
                ->whereNull('supplier_id')
                ->exists();

            $bySupplier = $allItems->groupBy(fn($item) => $item->product->supplier_id ?? 'no_supplier');

            foreach ($bySupplier as $supplierId => $supplierItems) {
                // Skip if supplier has an active (draft/submitted/confirmed) PO already
                if ($supplierId === 'no_supplier' && $hasNoSupplierActivePO) continue;
                if ($supplierId !== 'no_supplier' && in_array($supplierId, $supplierIdsWithActivePO)) continue;

                $firstProduct = $supplierItems->first()->product;
                $supplierObj  = $firstProduct->supplier;
                $supplierName = $supplierObj?->name ?? '(No Supplier)';

                $rows = [];
                foreach ($supplierItems->groupBy('product_variant_id') as $variantId => $group) {
                    $first = $group->first();
                    $rows[] = [
                        'product_id'     => $first->product->id,
                        'product_name'   => $first->product->name,
                        'product_code'   => $first->product->product_code,
                        'variant_id'     => $first->variant?->id,
                        'variant_label'  => $first->variant?->label,
                        'total_demanded' => $group->sum('quantity'),
                        'supplier_stock' => $first->variant?->supplier_stock ?? 0,
                        'unit_cost'      => $first->product->price,
                        'product'        => $first->product,
                        'variant'        => $first->variant,
                        'remaining'      => $first->variant ? $first->variant->remaining_stock : 0,
                    ];
                }

                if (!empty($rows)) {
                    $demandBySupplier[$supplierId] = [
                        'supplier_id'   => $supplierId === 'no_supplier' ? null : $supplierId,
                        'supplier_name' => $supplierName,
                        'rows'          => $rows,
                    ];
                }
            }
        }

        $suppliers = \App\Models\Supplier::where('is_active', true)->orderBy('name')->get();
        $purchaseOrders = $selectedTrip
            ? PurchaseOrder::where('trip_id', $selectedTrip->id)->with('items.product', 'items.variant', 'supplier')->latest()->get()
            : collect();

        return view('purchasing.index', compact('trips', 'selectedTrip', 'demandBySupplier', 'purchaseOrders', 'suppliers'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'trip_id'                        => 'required|exists:trips,id',
            'supplier_id'                    => 'nullable|exists:suppliers,id',
            'items'                          => 'required|array|min:1',
            'items.*.product_id'             => 'required|exists:products,id',
            'items.*.product_variant_id'     => 'nullable|exists:product_variants,id',
            'items.*.quantity_ordered'       => 'required|integer|min:1',
            'items.*.unit_cost'              => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($request, &$po) {
            $po = PurchaseOrder::create([
                'trip_id'     => $request->trip_id,
                'supplier_id' => $request->supplier_id ?: null,
                'created_by'  => Auth::id(),
                'purchased_at'=> now()->toDateString(),
            ]);

            $total = 0;
            foreach ($request->items as $item) {
                $lineTotal = $item['unit_cost'] * $item['quantity_ordered'];
                $po->items()->create([
                    'product_id'         => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'quantity_ordered'   => $item['quantity_ordered'],
                    'unit_cost'          => $item['unit_cost'],
                    'line_total'         => $lineTotal,
                ]);
                $total += $lineTotal;
            }
            $po->update(['total_amount' => $total, 'status' => 'submitted']);
        });

        return redirect()->route('purchasing.show', $po)->with('success', 'Purchase order created.');
    }

    public function show(PurchaseOrder $purchasing)
    {
        $purchasing->load(['items.product', 'items.variant', 'trip', 'supplier']);
        return view('purchasing.show', compact('purchasing'));
    }

    public function edit(PurchaseOrder $purchasing)
    {
        $purchasing->load(['items.product', 'items.variant', 'trip', 'supplier']);
        $suppliers = \App\Models\Supplier::where('is_active', true)->orderBy('name')->get();
        return view('purchasing.edit', compact('purchasing', 'suppliers'));
    }

    public function update(Request $request, PurchaseOrder $purchasing)
    {
        $request->validate([
            'supplier_id'  => 'nullable|exists:suppliers,id',
            'purchased_at' => 'nullable|date',
            'status'       => 'required|in:draft,submitted,confirmed,arrived',
            'notes'        => 'nullable|string',
            'items'        => 'required|array|min:1',
            'items.*.id'            => 'required|exists:purchase_order_items,id',
            'items.*.quantity_ordered' => 'required|integer|min:0',
            'items.*.unit_cost'     => 'required|numeric|min:0',
        ]);

        \DB::transaction(function () use ($request, $purchasing) {
            $purchasing->update([
                'supplier_id'  => $request->supplier_id ?: null,
                'purchased_at' => $request->purchased_at,
                'status'       => $request->status,
                'notes'        => $request->notes,
            ]);

            $total = 0;
            foreach ($request->items as $itemData) {
                $poItem = $purchasing->items()->find($itemData['id']);
                if ($poItem) {
                    $lineTotal = $itemData['unit_cost'] * $itemData['quantity_ordered'];
                    $poItem->update([
                        'quantity_ordered' => $itemData['quantity_ordered'],
                        'unit_cost'        => $itemData['unit_cost'],
                        'line_total'       => $lineTotal,
                    ]);
                    $total += $lineTotal;
                }
            }
            $purchasing->update(['total_amount' => $total]);
        });

        return redirect()->route('purchasing.show', $purchasing)->with('success', 'Purchase order updated.');
    }

    public function destroy(PurchaseOrder $purchasing)
    {
        \DB::transaction(function () use ($purchasing) {
            $purchasing->items()->delete();
            $purchasing->delete();
        });
        return redirect()->route('purchasing.index', ['trip_id' => $purchasing->trip_id])
            ->with('success', 'Purchase order deleted.');
    }

    /**
     * Confirm arrival and run FIFO allocation with partial fill support.
     *
     * Example: Customer A ordered 10, Customer B ordered 5, received = 12.
     * → Customer A gets 10 (fully filled), Customer B gets 2 (partially filled).
     * The original order item for B is split: 2 marked 'arrived', remaining 3 marked 'sold_out'.
     */
    public function confirmArrival(Request $request, PurchaseOrder $purchasing)
    {
        $request->validate([
            'items'                      => 'required|array',
            'items.*.id'                 => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received'  => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($request, $purchasing) {
            $affectedOrderIds = [];

            foreach ($request->items as $itemData) {
                $poItem = $purchasing->items()->find($itemData['id']);
                $poItem->update(['quantity_received' => $itemData['quantity_received']]);

                // Bug 3 fix: INCREMENT supplier_stock, don't overwrite
                // Multiple POs may deliver to the same variant
                if ($poItem->product_variant_id) {
                    ProductVariant::where('id', $poItem->product_variant_id)
                        ->increment('supplier_stock', $itemData['quantity_received']);
                }

                // FIFO: get pending order items oldest first
                $pendingItems = OrderItem::where(function ($q) use ($poItem) {
                        if ($poItem->product_variant_id) {
                            $q->where('product_variant_id', $poItem->product_variant_id);
                        } else {
                            $q->where('product_id', $poItem->product_id)
                              ->whereNull('product_variant_id');
                        }
                    })
                    ->whereIn('status', ['pending', 'confirmed'])
                    ->whereHas('order', fn($q) => $q->where('trip_id', $purchasing->trip_id))
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->orderBy('orders.created_at', 'asc')
                    ->orderBy('order_items.id', 'asc')
                    ->select('order_items.*')
                    ->get();

                $remaining = (int) $itemData['quantity_received'];

                foreach ($pendingItems as $orderItem) {
                    $affectedOrderIds[] = $orderItem->order_id;

                    if ($remaining <= 0) {
                        $orderItem->update(['status' => 'sold_out']);
                    } elseif ($orderItem->quantity <= $remaining) {
                        $remaining -= $orderItem->quantity;
                        $orderItem->update(['status' => 'arrived']);
                    } else {
                        $arrivedQty  = $remaining;
                        $soldOutQty  = $orderItem->quantity - $remaining;
                        $remaining   = 0;

                        $orderItem->update([
                            'quantity'   => $arrivedQty,
                            'line_total' => $orderItem->unit_price * $arrivedQty,
                            'status'     => 'arrived',
                        ]);

                        OrderItem::create([
                            'order_id'           => $orderItem->order_id,
                            'product_id'         => $orderItem->product_id,
                            'product_variant_id' => $orderItem->product_variant_id,
                            'quantity'           => $soldOutQty,
                            'unit_price'         => $orderItem->unit_price,
                            'line_total'         => $orderItem->unit_price * $soldOutQty,
                            'status'             => 'sold_out',
                            'notes'              => 'Partial — only '.$arrivedQty.' of '.($arrivedQty + $soldOutQty).' available (FIFO)',
                        ]);
                    }
                }

                // Update allocated_qty from actual arrived items
                if ($poItem->product_variant_id) {
                    $allocatedQty = OrderItem::where('product_variant_id', $poItem->product_variant_id)
                        ->whereHas('order', fn($q) => $q->where('trip_id', $purchasing->trip_id))
                        ->where('status', 'arrived')
                        ->sum('quantity');

                    ProductVariant::where('id', $poItem->product_variant_id)
                        ->update(['allocated_qty' => $allocatedQty]);
                }
            }

            $purchasing->update(['status' => 'arrived']);

            // Bug 4 fix: recalculate totals for ALL affected orders after FIFO
            $promoService = app(\App\Services\PromoService::class);
            foreach (array_unique($affectedOrderIds) as $orderId) {
                $affectedOrder = \App\Models\Order::find($orderId);
                if ($affectedOrder) {
                    $calc = $promoService->recalculate($affectedOrder->fresh());
                    $affectedOrder->update([
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
        });

        return back()->with('success', 'Arrival confirmed. Stock allocated via FIFO (partial fills split automatically).');
    }
}
