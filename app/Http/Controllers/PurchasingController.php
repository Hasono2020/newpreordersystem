<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
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

        // Lightweight: only load POs and pass trip info.
        // Demand data is loaded asynchronously via demandApi() to keep page load fast.
        $purchaseOrders = $selectedTrip
            ? PurchaseOrder::where('trip_id', $selectedTrip->id)
                ->with('supplier')
                ->withCount('items')
                ->latest()->get()
            : collect();

        return view('purchasing.index', compact('trips', 'selectedTrip', 'purchaseOrders'));
    }

    /**
     * API: return demand grouped by supplier for a trip.
     * Called asynchronously by the purchasing page after load.
     */
    public function demandApi(Request $request)
    {
        $trip = Trip::find($request->trip_id);
        if (!$trip) return response()->json([]);

        // Single optimised query using joins — avoids loading full Eloquent models
        $rows = DB::table('order_items')
            ->join('orders',           'orders.id',           '=', 'order_items.order_id')
            ->join('products',         'products.id',         '=', 'order_items.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('suppliers',    'suppliers.id',        '=', 'products.supplier_id')
            ->where('orders.trip_id', $trip->id)
            ->whereIn('order_items.status', ['pending', 'confirmed'])
            ->select([
                'products.supplier_id',
                DB::raw('COALESCE(suppliers.name, "(No Supplier)") as supplier_name'),
                'products.id as product_id',
                'products.name as product_name',
                'products.product_code',
                'product_variants.id as variant_id',
                'product_variants.color',
                'product_variants.size',
                DB::raw('COALESCE(product_variants.supplier_stock, 0) as supplier_stock'),
                DB::raw('SUM(order_items.quantity) as total_demanded'),
            ])
            ->groupBy([
                'products.supplier_id', 'supplier_name',
                'products.id', 'products.name', 'products.product_code',
                'product_variants.id', 'product_variants.color', 'product_variants.size',
                'product_variants.supplier_stock',
            ])
            ->orderBy('supplier_name')
            ->orderBy('products.name')
            ->get();

        // Suppliers whose POs are confirmed/arrived — hide their demand entirely
        $lockedSupplierIds = PurchaseOrder::where('trip_id', $trip->id)
            ->whereIn('status', ['confirmed', 'arrived'])
            ->whereNotNull('supplier_id')
            ->pluck('supplier_id')->unique()->toArray();

        $lockedNoSupplier = PurchaseOrder::where('trip_id', $trip->id)
            ->whereIn('status', ['confirmed', 'arrived'])
            ->whereNull('supplier_id')->exists();

        // For each variant, get total quantity already in active POs for this trip.
        // We compare this against total demand — only hide/reduce demand by what's covered.
        $poQtyByVariant = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.trip_id', $trip->id)
            ->whereNotIn('purchase_orders.status', ['cancelled', 'arrived'])
            ->select([
                'purchase_order_items.product_id',
                'purchase_order_items.product_variant_id',
                DB::raw('SUM(purchase_order_items.quantity_ordered) as po_qty'),
            ])
            ->groupBy('purchase_order_items.product_id', 'purchase_order_items.product_variant_id')
            ->get()
            ->keyBy(fn($r) => $r->product_id . '_' . ($r->product_variant_id ?? 'null'));

        // Draft/submitted POs for warning banners
        $draftPOsRaw = PurchaseOrder::where('trip_id', $trip->id)
            ->whereIn('status', ['draft', 'submitted'])
            ->get(['id', 'po_number', 'supplier_id', 'status']);
        $draftPOs = $draftPOsRaw->groupBy(fn($p) => $p->supplier_id ?? 'no_supplier');

        // Group into supplier → products → variants
        $bySupplier = [];
        foreach ($rows as $row) {
            $supId = $row->supplier_id ?? 'no_supplier';

            // Skip if supplier is fully locked (confirmed/arrived PO)
            if ($supId === 'no_supplier' && $lockedNoSupplier) continue;
            if ($supId !== 'no_supplier' && in_array($supId, $lockedSupplierIds)) continue;

            // Subtract PO qty from demand — only show uncovered remainder
            $poKey       = $row->product_id . '_' . ($row->variant_id ?? 'null');
            $poQty       = isset($poQtyByVariant[$poKey]) ? (int) $poQtyByVariant[$poKey]->po_qty : 0;
            $netDemanded = (int) $row->total_demanded - $poQty;
            if ($netDemanded <= 0) continue; // fully covered — skip

            if (!isset($bySupplier[$supId])) {
                $draftPOList = $draftPOs->get($supId) ?? collect();
                // Get the single active PO for this supplier (enforced 1 per supplier)
                $activePO = $draftPOList->first();
                $bySupplier[$supId] = [
                    'supplier_id'   => $supId === 'no_supplier' ? null : $supId,
                    'supplier_name' => $row->supplier_name,
                    'active_po'     => $activePO ? ['id'=>$activePO->id,'po_number'=>$activePO->po_number,'status'=>$activePO->status] : null,
                    'draft_pos'     => $draftPOList->map(fn($p) => ['id'=>$p->id,'po_number'=>$p->po_number,'status'=>$p->status])->values()->all(),
                    'rows'          => [],
                ];
            }

            // Build variant label
            $parts = array_filter([$row->color, $row->size]);
            $label = $parts ? implode(' / ', $parts) : 'Default';

            $bySupplier[$supId]['rows'][] = [
                'product_id'     => $row->product_id,
                'product_name'   => $row->product_name,
                'product_code'   => $row->product_code ?? '',
                'variant_id'     => $row->variant_id,
                'variant_label'  => $label,
                'total_demanded' => $netDemanded,  // net uncovered demand
                'supplier_stock' => (int) $row->supplier_stock,
            ];
        }

        // Remove suppliers with no remaining demand
        $bySupplier = array_filter($bySupplier, fn($s) => !empty($s['rows']));

        return response()->json(array_values($bySupplier));
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
            $supplierId = $request->supplier_id ?: null;

            // Enforce 1 active PO per supplier per trip — merge into existing if found
            $existingPO = PurchaseOrder::where('trip_id', $request->trip_id)
                ->where('supplier_id', $supplierId)
                ->whereNotIn('status', ['cancelled', 'arrived'])
                ->first();

            if ($existingPO) {
                $po = $existingPO; // merge into existing
            } else {
                $po = PurchaseOrder::create([
                    'trip_id'      => $request->trip_id,
                    'supplier_id'  => $supplierId,
                    'created_by'   => Auth::id(),
                    'purchased_at' => now()->toDateString(),
                ]);
            }

            // Merge into existing line if same variant, insert new line otherwise
            $existing = DB::table('purchase_order_items')
                ->where('purchase_order_id', $po->id)
                ->get()
                ->keyBy(fn($r) => $r->product_id.'_'.($r->product_variant_id ?? 'null'));

            $inserts = [];
            $now     = now();
            foreach ($request->items as $item) {
                $qty       = (int)   $item['quantity_ordered'];
                $cost      = (float) $item['unit_cost'];
                $varId     = $item['product_variant_id'] ?: null;
                $key       = $item['product_id'].'_'.($varId ?? 'null');

                if (isset($existing[$key])) {
                    $newQty  = $existing[$key]->quantity_ordered + $qty;
                    $useCost = $cost ?: $existing[$key]->unit_cost;
                    DB::table('purchase_order_items')->where('id', $existing[$key]->id)->update([
                        'quantity_ordered' => $newQty,
                        'unit_cost'        => $useCost,
                        'line_total'       => $newQty * $useCost,
                        'updated_at'       => $now,
                    ]);
                } else {
                    $inserts[] = [
                        'purchase_order_id'  => $po->id,
                        'product_id'         => $item['product_id'],
                        'product_variant_id' => $varId,
                        'quantity_ordered'   => $qty,
                        'quantity_received'  => 0,
                        'unit_cost'          => $cost,
                        'line_total'         => $qty * $cost,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }
            }
            if ($inserts) DB::table('purchase_order_items')->insert($inserts);

            $total = DB::table('purchase_order_items')
                ->where('purchase_order_id', $po->id)->sum('line_total');
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

        // Load uncovered demand for this PO's supplier+trip
        // Shows net demand = total ordered - already in ALL active POs for this trip
        // Same variant can appear if there's remaining uncovered demand
        $allDemand = DB::table('order_items')
            ->join('orders',           'orders.id',           '=', 'order_items.order_id')
            ->join('products',         'products.id',         '=', 'order_items.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->where('orders.trip_id', $purchasing->trip_id)
            ->where('products.supplier_id', $purchasing->supplier_id)
            ->whereIn('order_items.status', ['pending', 'confirmed'])
            ->select([
                'products.id as product_id',
                'products.name as product_name',
                'products.product_code',
                'product_variants.id as variant_id',
                'product_variants.color',
                'product_variants.size',
                DB::raw('COALESCE(product_variants.supplier_stock, 0) as supplier_stock'),
                DB::raw('SUM(order_items.quantity) as total_demanded'),
            ])
            ->groupBy([
                'products.id','products.name','products.product_code',
                'product_variants.id','product_variants.color','product_variants.size',
                'product_variants.supplier_stock',
            ])
            ->orderBy('products.name')
            ->get();

        // Get qty already in ALL active POs for this trip (keyed by product+variant)
        $poQtyMap = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.trip_id', $purchasing->trip_id)
            ->whereNotIn('purchase_orders.status', ['cancelled', 'arrived'])
            ->select([
                'purchase_order_items.product_id',
                'purchase_order_items.product_variant_id',
                DB::raw('SUM(purchase_order_items.quantity_ordered) as po_qty'),
            ])
            ->groupBy('purchase_order_items.product_id', 'purchase_order_items.product_variant_id')
            ->get()
            ->keyBy(fn($r) => $r->product_id . '_' . ($r->product_variant_id ?? 'null'));

        $newDemand = $allDemand->map(function($r) use ($poQtyMap) {
            $parts = array_filter([$r->color, $r->size]);
            $r->variant_label = $parts ? implode(' / ', $parts) : 'Default';
            // Net uncovered demand
            $key    = $r->product_id . '_' . ($r->variant_id ?? 'null');
            $poQty  = isset($poQtyMap[$key]) ? (int) $poQtyMap[$key]->po_qty : 0;
            $r->total_demanded = max(0, (int) $r->total_demanded - $poQty);
            return $r;
        })->filter(fn($r) => $r->total_demanded > 0)->values();

        return view('purchasing.edit', compact('purchasing', 'suppliers', 'newDemand'));
    }

    public function update(Request $request, PurchaseOrder $purchasing)
    {
        $request->validate([
            'supplier_id'  => 'nullable|exists:suppliers,id',
            'purchased_at' => 'nullable|date',
            'status'       => 'required|in:draft,submitted,confirmed,arrived',
            'notes'        => 'nullable|string',
            // new_items only — existing items are NOT submitted in the form to avoid
            // max_input_vars truncation. Existing items are saved via updateItem() AJAX.
            'new_items'                      => 'nullable|array',
            'new_items.*.product_id'         => 'nullable|integer',
            'new_items.*.product_variant_id' => 'nullable|integer',
            'new_items.*.quantity_ordered'   => 'nullable|integer|min:1',
            'new_items.*.unit_cost'          => 'nullable|numeric|min:0',
        ]);

        \DB::transaction(function () use ($request, $purchasing) {
            $purchasing->update([
                'supplier_id'  => $request->supplier_id ?: null,
                'purchased_at' => $request->purchased_at,
                'status'       => $request->status,
                'notes'        => $request->notes,
            ]);

            // Recalculate total from existing items (unchanged)
            $total = (float) DB::table('purchase_order_items')
                ->where('purchase_order_id', $purchasing->id)
                ->sum('line_total');

            // Merge into existing line if same variant, insert new line otherwise
            if ($request->filled('new_items')) {
                $existing = DB::table('purchase_order_items')
                    ->where('purchase_order_id', $purchasing->id)
                    ->get()
                    ->keyBy(fn($r) => $r->product_id.'_'.($r->product_variant_id ?? 'null'));

                $inserts = [];
                $now     = now();
                foreach ($request->new_items as $ni) {
                    if (empty($ni['product_id']) || empty($ni['quantity_ordered'])) continue;
                    $qty   = (int)   $ni['quantity_ordered'];
                    $cost  = (float) ($ni['unit_cost'] ?? 0);
                    $varId = $ni['product_variant_id'] ?: null;
                    $key   = $ni['product_id'].'_'.($varId ?? 'null');

                    if (isset($existing[$key])) {
                        $newQty  = $existing[$key]->quantity_ordered + $qty;
                        $useCost = $cost ?: $existing[$key]->unit_cost;
                        DB::table('purchase_order_items')->where('id', $existing[$key]->id)->update([
                            'quantity_ordered' => $newQty,
                            'unit_cost'        => $useCost,
                            'line_total'       => $newQty * $useCost,
                            'updated_at'       => $now,
                        ]);
                    } else {
                        $inserts[] = [
                            'purchase_order_id'  => $purchasing->id,
                            'product_id'         => $ni['product_id'],
                            'product_variant_id' => $varId,
                            'quantity_ordered'   => $qty,
                            'quantity_received'  => 0,
                            'unit_cost'          => $cost,
                            'line_total'         => $qty * $cost,
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ];
                    }
                }
                if ($inserts) DB::table('purchase_order_items')->insert($inserts);
            }

            $purchasing->update(['total_amount' => $total]);
        });

        return redirect()->route('purchasing.show', $purchasing)->with('success', 'Purchase order updated.');
    }

    /**
     * AJAX: update a single existing PO item inline (avoids max_input_vars for large POs)
     */
    public function updateItem(Request $request, PurchaseOrder $purchasing, int $itemId)
    {
        $request->validate([
            'quantity_ordered' => 'required|integer|min:0',
            'unit_cost'        => 'required|numeric|min:0',
        ]);

        $item = DB::table('purchase_order_items')
            ->where('id', $itemId)
            ->where('purchase_order_id', $purchasing->id)
            ->first();

        if (!$item) return response()->json(['error' => 'Item not found'], 404);

        $qty       = (int)   $request->quantity_ordered;
        $cost      = (float) $request->unit_cost;
        $lineTotal = $qty * $cost;

        DB::table('purchase_order_items')->where('id', $itemId)->update([
            'quantity_ordered' => $qty,
            'unit_cost'        => $cost,
            'line_total'       => $lineTotal,
            'updated_at'       => now(),
        ]);

        // Recalculate PO total
        $total = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchasing->id)
            ->sum('line_total');
        $purchasing->update(['total_amount' => $total]);

        return response()->json(['ok' => true, 'line_total' => $lineTotal, 'po_total' => $total]);
    }

    public function addItem(Request $request, PurchaseOrder $purchasing)
    {
        $request->validate([
            'product_id'         => 'required|integer',
            'product_variant_id' => 'nullable|integer',
            'quantity_ordered'   => 'required|integer|min:1',
            'unit_cost'          => 'required|numeric|min:0',
        ]);

        $varId = $request->product_variant_id ?: null;
        $qty   = (int)   $request->quantity_ordered;
        $cost  = (float) $request->unit_cost;
        $key   = $request->product_id . '_' . ($varId ?? 'null');
        $now   = now();

        // Merge into existing line if same variant exists
        $existing = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchasing->id)
            ->where('product_id', $request->product_id)
            ->where('product_variant_id', $varId)
            ->first();

        if ($existing) {
            $newQty = $existing->quantity_ordered + $qty;
            DB::table('purchase_order_items')->where('id', $existing->id)->update([
                'quantity_ordered' => $newQty,
                'unit_cost'        => $cost ?: $existing->unit_cost,
                'line_total'       => $newQty * ($cost ?: $existing->unit_cost),
                'updated_at'       => $now,
            ]);
            $itemId = $existing->id;
        } else {
            $itemId = DB::table('purchase_order_items')->insertGetId([
                'purchase_order_id'  => $purchasing->id,
                'product_id'         => $request->product_id,
                'product_variant_id' => $varId,
                'quantity_ordered'   => $qty,
                'quantity_received'  => 0,
                'unit_cost'          => $cost,
                'line_total'         => $qty * $cost,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        $total = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchasing->id)->sum('line_total');
        $purchasing->update(['total_amount' => $total]);

        return response()->json(['ok' => true, 'item_id' => $itemId, 'po_total' => $total]);
    }

    public function deleteItem(Request $request, PurchaseOrder $purchasing, int $itemId)
    {
        DB::table('purchase_order_items')
            ->where('id', $itemId)
            ->where('purchase_order_id', $purchasing->id)
            ->delete();

        $total = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchasing->id)
            ->sum('line_total');
        $purchasing->update(['total_amount' => $total]);

        return response()->json(['ok' => true, 'po_total' => $total]);
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
                    ->orderBy('orders.ordered_at', 'asc')
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