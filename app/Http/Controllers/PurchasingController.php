<?php

namespace App\Http\Controllers;

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

        // Count remaining uncovered demand for this supplier
        $remainingDemand = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.trip_id', $purchasing->trip_id)
            ->where('products.supplier_id', $purchasing->supplier_id)
            ->whereIn('order_items.status', ['pending', 'confirmed'])
            ->count();

        $coveredByPO = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.trip_id', $purchasing->trip_id)
            ->where('purchase_orders.supplier_id', $purchasing->supplier_id)
            ->whereNotIn('purchase_orders.status', ['cancelled', 'arrived'])
            ->sum('purchase_order_items.quantity_ordered');

        $totalDemand = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.trip_id', $purchasing->trip_id)
            ->where('products.supplier_id', $purchasing->supplier_id)
            ->whereIn('order_items.status', ['pending', 'confirmed'])
            ->sum('order_items.quantity');

        $netRemaining = max(0, $totalDemand - $coveredByPO);
        $msg = 'Purchase order updated.';
        if ($netRemaining > 0) {
            $msg .= " Note: {$netRemaining} pcs still have uncovered demand — go back to Purchasing to add them.";
        } else {
            $msg .= ' All demand for this supplier is now covered.';
        }

        return redirect()->route('purchasing.show', $purchasing)->with('success', $msg);
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


        /**
     * One-click: create or merge into existing PO for a supplier,
     * syncing all current demand for that supplier+trip in one shot.
     * Redirects to the Edit PO page so unit costs can be set.
     */
    public function createOrSyncAll(Request $request)
    {
        $request->validate([
            'trip_id'     => 'required|exists:trips,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ]);

        $supplierId = $request->supplier_id ?: null;
        $tripId     = $request->trip_id;

        $demand = DB::table('order_items')
            ->join('orders',   'orders.id',   '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.trip_id', $tripId)
            ->where('products.supplier_id', $supplierId)
            ->whereIn('order_items.status', ['pending', 'confirmed'])
            ->selectRaw('order_items.product_variant_id, products.id as product_id, SUM(order_items.quantity) as total_qty')
            ->groupBy('order_items.product_variant_id', 'products.id')
            ->get();

        if ($demand->isEmpty()) {
            return back()->with('error', 'No uncovered demand found for this supplier.');
        }

        $po = DB::transaction(function () use ($demand, $supplierId, $tripId) {
            $po = PurchaseOrder::where('trip_id', $tripId)
                ->where('supplier_id', $supplierId)
                ->whereNotIn('status', ['cancelled', 'arrived'])
                ->first();

            if (!$po) {
                $po = PurchaseOrder::create([
                    'trip_id'      => $tripId,
                    'supplier_id'  => $supplierId,
                    'created_by'   => Auth::id(),
                    'purchased_at' => now()->toDateString(),
                    'status'       => 'draft',
                ]);
            }

            $existing = DB::table('purchase_order_items')
                ->where('purchase_order_id', $po->id)
                ->get()
                ->keyBy(fn($r) => $r->product_id . '_' . ($r->product_variant_id ?? 'null'));

            $inserts = [];
            $now     = now();

            foreach ($demand as $d) {
                $totalQty = (int) $d->total_qty;
                $key      = $d->product_id . '_' . ($d->product_variant_id ?? 'null');

                if (isset($existing[$key])) {
                    $item = $existing[$key];
                    if ($item->quantity_ordered != $totalQty) {
                        DB::table('purchase_order_items')->where('id', $item->id)->update([
                            'quantity_ordered' => $totalQty,
                            'line_total'       => $totalQty * $item->unit_cost,
                            'updated_at'       => $now,
                        ]);
                    }
                } else {
                    $inserts[] = [
                        'purchase_order_id'  => $po->id,
                        'product_id'         => $d->product_id,
                        'product_variant_id' => $d->product_variant_id,
                        'quantity_ordered'   => $totalQty,
                        'quantity_received'  => 0,
                        'unit_cost'          => 0,
                        'line_total'         => 0,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }
            }

            if ($inserts) DB::table('purchase_order_items')->insert($inserts);

            $total = DB::table('purchase_order_items')
                ->where('purchase_order_id', $po->id)->sum('line_total');
            $po->update(['total_amount' => $total]);

            return $po;
        });

        return redirect()->route('purchasing.edit', $po)
            ->with('success', 'PO ready — set unit costs and click Save Changes.');
    }

    /**
     * Sync PO items to match current total demand for this supplier+trip.
     * Called when staff clicks "Add to PO" from purchasing index.
     * Updates existing line qtys + inserts new variant lines in one shot.
     */
    public function syncDemand(PurchaseOrder $purchasing)
    {
        // Get ALL current demand for this supplier+trip grouped by product+variant
        $demand = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.trip_id', $purchasing->trip_id)
            ->where('products.supplier_id', $purchasing->supplier_id)
            ->whereIn('order_items.status', ['pending', 'confirmed'])
            ->selectRaw('order_items.product_variant_id, products.id as product_id, SUM(order_items.quantity) as total_qty')
            ->groupBy('order_items.product_variant_id', 'products.id')
            ->get()
            ->keyBy(fn($r) => $r->product_id.'_'.($r->product_variant_id ?? 'null'));

        // Get existing PO items
        $existing = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchasing->id)
            ->get()
            ->keyBy(fn($r) => $r->product_id.'_'.($r->product_variant_id ?? 'null'));

        $now     = now();
        $inserts = [];

        foreach ($demand as $key => $d) {
            $totalQty = (int) $d->total_qty;
            if (isset($existing[$key])) {
                // Update existing line to match total demand
                $item = $existing[$key];
                if ($item->quantity_ordered != $totalQty) {
                    DB::table('purchase_order_items')->where('id', $item->id)->update([
                        'quantity_ordered' => $totalQty,
                        'line_total'       => $totalQty * $item->unit_cost,
                        'updated_at'       => $now,
                    ]);
                }
            } else {
                // New variant — insert
                $inserts[] = [
                    'purchase_order_id'  => $purchasing->id,
                    'product_id'         => $d->product_id,
                    'product_variant_id' => $d->product_variant_id,
                    'quantity_ordered'   => $totalQty,
                    'quantity_received'  => 0,
                    'unit_cost'          => 0,
                    'line_total'         => 0,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            }
        }

        if ($inserts) DB::table('purchase_order_items')->insert($inserts);

        // Recalculate total
        $total = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchasing->id)->sum('line_total');
        $purchasing->update(['total_amount' => $total]);

        return redirect()->route('purchasing.edit', $purchasing)
            ->with('success', 'PO synced with latest demand. Set unit costs and click Save Changes.');
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
        $wasArrived = $purchasing->status === 'arrived';

        \DB::transaction(function () use ($purchasing, $wasArrived) {

            // If the PO was already arrived, reverse the FIFO allocation first
            if ($wasArrived) {
                @set_time_limit(600);
                @ini_set('memory_limit', '1024M');
                DB::connection()->disableQueryLog();

                // Load this PO's items (product/variant + received qty)
                $poItems = DB::table('purchase_order_items')
                    ->where('purchase_order_id', $purchasing->id)
                    ->get();

                $variantIds        = [];
                $productNoVariant  = [];

                foreach ($poItems as $pi) {
                    if ($pi->product_variant_id) {
                        $variantIds[$pi->product_variant_id] = true;
                    } else {
                        $productNoVariant[$pi->product_id] = true;
                    }
                }

                $tripId = $purchasing->trip_id;

                // ── 1) Delete the sold_out split rows FIFO created ──────────
                // These are identified by the "Partial — only" notes marker.
                $splitQuery = DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.trip_id', $tripId)
                    ->where('order_items.status', 'sold_out')
                    ->where('order_items.notes', 'like', 'Partial — only%');

                if ($variantIds) {
                    $splitQuery->whereIn('order_items.product_variant_id', array_keys($variantIds));
                }
                $splitIds = $splitQuery->pluck('order_items.id')->all();
                if ($splitIds) {
                    DB::table('order_items')->whereIn('id', $splitIds)->delete();
                }

                // ── 2) Restore arrived/sold_out items back to confirmed ─────
                // Collect affected order ids first (for recalculation)
                $affectedQuery = DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.trip_id', $tripId)
                    ->whereIn('order_items.status', ['arrived', 'sold_out']);

                if ($variantIds || $productNoVariant) {
                    $affectedQuery->where(function ($q) use ($variantIds, $productNoVariant) {
                        if ($variantIds) {
                            $q->whereIn('order_items.product_variant_id', array_keys($variantIds));
                        }
                        if ($productNoVariant) {
                            $q->orWhere(function ($q2) use ($productNoVariant) {
                                $q2->whereNull('order_items.product_variant_id')
                                   ->whereIn('order_items.product_id', array_keys($productNoVariant));
                            });
                        }
                    });
                }

                $affectedOrderIds = $affectedQuery->pluck('orders.id')->unique()->values()->all();

                // Orders that had sold_out items will change total when restored,
                // so only those need recalculation (arrived->confirmed doesn't change total).
                $soldOutOrderIds = (clone $affectedQuery)
                    ->where('order_items.status', 'sold_out')
                    ->pluck('orders.id')->unique()->values()->all();

                // Restore items based on their order's payment status:
                //   fully paid -> confirmed,  unpaid/partial -> pending
                $paidOrderIds = DB::table('orders')
                    ->whereIn('id', $affectedOrderIds)
                    ->where('payment_status', 'paid')
                    ->pluck('id')->all();

                $confirmIds = $paidOrderIds
                    ? (clone $affectedQuery)->whereIn('orders.id', $paidOrderIds)->pluck('order_items.id')->all()
                    : [];
                $pendingIds = (clone $affectedQuery)
                    ->when($paidOrderIds, fn($q) => $q->whereNotIn('orders.id', $paidOrderIds))
                    ->pluck('order_items.id')->all();

                foreach (array_chunk($confirmIds, 1000) as $chunk) {
                    DB::table('order_items')->whereIn('id', $chunk)
                        ->update(['status' => 'confirmed', 'updated_at' => now()]);
                }
                foreach (array_chunk($pendingIds, 1000) as $chunk) {
                    DB::table('order_items')->whereIn('id', $chunk)
                        ->update(['status' => 'pending', 'updated_at' => now()]);
                }

                // ── 3) Reset supplier_stock + allocated_qty to 0 ───────────
                // Full clean undo: clear stock for all variants this PO touched,
                // so the next arrival cycle starts fresh (no accumulation).
                $resetVariantIds = array_keys($variantIds);
                foreach (array_chunk($resetVariantIds, 1000) as $chunk) {
                    DB::table('product_variants')->whereIn('id', $chunk)
                        ->update(['supplier_stock' => 0, 'allocated_qty' => 0]);
                }

                // ── 4) Recalculate ONLY orders whose totals changed ─────────
                // (orders that had sold_out items restored)
                $promoService = app(\App\Services\PromoService::class);
                foreach (array_chunk($soldOutOrderIds, 300) as $orderIdChunk) {
                    $orders = \App\Models\Order::with(['items.product', 'items.variant', 'customer', 'shippingArea'])
                        ->whereIn('id', $orderIdChunk)->get();
                    foreach ($orders as $affectedOrder) {
                        $calc = $promoService->recalculate($affectedOrder);
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
            }

            // Finally delete the PO and its items
            $purchasing->items()->delete();
            $purchasing->delete();
        });

        $msg = $wasArrived
            ? 'Purchase order deleted. Stock allocation reversed — affected orders restored to confirmed.'
            : 'Purchase order deleted.';

        return redirect()->route('purchasing.index', ['trip_id' => $purchasing->trip_id])
            ->with('success', $msg);
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
        if (!Auth::user()->hasPermission('purchasing.edit')) {
            abort(403, 'Only purchasing staff can confirm arrivals.');
        }

        // Items are sent as a single JSON field (avoids max_input_vars limit
        // on large POs). Fall back to the old array format if present.
        if ($request->filled('items_json')) {
            $items = json_decode($request->input('items_json'), true) ?: [];
            $request->merge(['items' => $items]);
        }

        $request->validate([
            'items'                      => 'required|array',
            'items.*.id'                 => 'required|integer',
            'items.*.quantity_received'  => 'required|integer|min:0',
        ]);

        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');
        DB::connection()->disableQueryLog();

        DB::transaction(function () use ($request, $purchasing) {

            // ── Step 1: load ALL PO items in ONE query ──────────────────
            $poItemIds = collect($request->items)->pluck('id')->all();
            $poItems   = DB::table('purchase_order_items')
                ->whereIn('id', $poItemIds)
                ->where('purchase_order_id', $purchasing->id)
                ->get()
                ->keyBy('id');

            // ── Step 2: load ALL pending order items for this trip in ONE query ──
            $allPending = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.trip_id', $purchasing->trip_id)
                ->whereIn('order_items.status', ['pending', 'confirmed'])
                ->orderBy('orders.ordered_at', 'asc')
                ->orderBy('order_items.id', 'asc')
                ->select(
                    'order_items.id', 'order_items.order_id', 'order_items.product_id',
                    'order_items.product_variant_id', 'order_items.quantity',
                    'order_items.unit_price', 'order_items.line_total'
                )
                ->get()
                ->groupBy(fn($r) => $r->product_variant_id
                    ? 'v_'.$r->product_variant_id
                    : 'p_'.$r->product_id
                );

            // ── Step 3: FIFO allocation entirely in PHP memory ──────────
            $affectedOrderIds = [];
            $ordersNeedingRecalc = [];
            $arrivedIds       = [];
            $soldOutIds       = [];
            $partialUpdates   = [];
            $orderItemInserts = [];
            $variantStockInc  = [];
            $variantIds       = [];
            $processedItemIds = [];

            $now = now();

            foreach ($request->items as $itemData) {
                $poItem = $poItems->get($itemData['id']);
                if (!$poItem) continue;

                $received = (int) $itemData['quantity_received'];

                if ($poItem->product_variant_id) {
                    $variantStockInc[$poItem->product_variant_id] =
                        ($variantStockInc[$poItem->product_variant_id] ?? 0) + $received;
                    $variantIds[$poItem->product_variant_id] = true;
                }

                $key = $poItem->product_variant_id
                    ? 'v_'.$poItem->product_variant_id
                    : 'p_'.$poItem->product_id;
                $pending   = $allPending->get($key, collect());
                $remaining = $received;

                foreach ($pending as $orderItem) {
                    if (isset($processedItemIds[$orderItem->id])) continue;
                    $processedItemIds[$orderItem->id] = true;
                    $affectedOrderIds[] = $orderItem->order_id;

                    if ($remaining <= 0) {
                        $soldOutIds[] = $orderItem->id;
                        // Item became sold_out -> order total changes -> needs recalc
                        $ordersNeedingRecalc[$orderItem->order_id] = true;
                    } elseif ($orderItem->quantity <= $remaining) {
                        $remaining -= $orderItem->quantity;
                        $arrivedIds[] = $orderItem->id;
                        // pending -> arrived: still counted in total, NO recalc needed
                    } else {
                        $arrivedQty = $remaining;
                        $soldOutQty = $orderItem->quantity - $remaining;
                        $remaining  = 0;
                        // Partial split -> order total changes -> needs recalc
                        $ordersNeedingRecalc[$orderItem->order_id] = true;

                        $partialUpdates[$orderItem->id] = [
                            'quantity'   => $arrivedQty,
                            'line_total' => $orderItem->unit_price * $arrivedQty,
                        ];
                        $orderItemInserts[] = [
                            'order_id'           => $orderItem->order_id,
                            'product_id'         => $orderItem->product_id,
                            'product_variant_id' => $orderItem->product_variant_id,
                            'quantity'           => $soldOutQty,
                            'unit_price'         => $orderItem->unit_price,
                            'line_total'         => $orderItem->unit_price * $soldOutQty,
                            'status'             => 'sold_out',
                            'notes'              => 'Partial — only '.$arrivedQty.' of '.($arrivedQty + $soldOutQty).' available (FIFO)',
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ];
                    }
                }
            }

            // ── Step 4: apply all updates in bulk ───────────────────────
            // Bulk-update quantity_received via single CASE query per chunk
            foreach (array_chunk($request->items, 500, true) as $chunk) {
                $ids = []; $cases = '';
                foreach ($chunk as $itemData) {
                    $id  = (int) $itemData['id'];
                    $qty = (int) $itemData['quantity_received'];
                    $cases .= "WHEN {$id} THEN {$qty} ";
                    $ids[] = $id;
                }
                if ($ids) {
                    $idList = implode(',', $ids);
                    DB::update("UPDATE purchase_order_items
                        SET quantity_received = CASE id {$cases} END, updated_at = ?
                        WHERE id IN ({$idList})", [$now]);
                }
            }

            foreach (array_chunk($arrivedIds, 1000) as $chunk) {
                DB::table('order_items')->whereIn('id', $chunk)->update(['status' => 'arrived', 'updated_at' => $now]);
            }
            foreach (array_chunk($soldOutIds, 1000) as $chunk) {
                DB::table('order_items')->whereIn('id', $chunk)->update(['status' => 'sold_out', 'updated_at' => $now]);
            }
            foreach ($partialUpdates as $id => $upd) {
                DB::table('order_items')->where('id', $id)->update([
                    'quantity'   => $upd['quantity'],
                    'line_total' => $upd['line_total'],
                    'status'     => 'arrived',
                    'updated_at' => $now,
                ]);
            }
            foreach (array_chunk($orderItemInserts, 500) as $chunk) {
                DB::table('order_items')->insert($chunk);
            }

            // ── Step 5: supplier_stock + allocated_qty (batched) ────────
            // SET stock to received qty (not increment) so repeated cycles
            // don't accumulate. Bulk CASE updates instead of per-variant queries.
            $stockVariantIds = array_keys($variantStockInc);
            foreach (array_chunk($stockVariantIds, 500) as $chunk) {
                $cases = '';
                foreach ($chunk as $vid) {
                    $rec = (int) $variantStockInc[$vid];
                    $cases .= "WHEN {$vid} THEN {$rec} ";
                }
                $idList = implode(',', $chunk);
                DB::update("UPDATE product_variants
                    SET supplier_stock = CASE id {$cases} END
                    WHERE id IN ({$idList})");
            }

            // allocated_qty for ALL affected variants in ONE grouped query
            $variantIdList = array_keys($variantIds);
            if ($variantIdList) {
                $allocatedByVariant = DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.trip_id', $purchasing->trip_id)
                    ->whereIn('order_items.product_variant_id', $variantIdList)
                    ->where('order_items.status', 'arrived')
                    ->groupBy('order_items.product_variant_id')
                    ->selectRaw('order_items.product_variant_id, SUM(order_items.quantity) as qty')
                    ->pluck('qty', 'product_variant_id');

                foreach (array_chunk($variantIdList, 500) as $chunk) {
                    $cases = '';
                    foreach ($chunk as $vid) {
                        $qty = (int) ($allocatedByVariant[$vid] ?? 0);
                        $cases .= "WHEN {$vid} THEN {$qty} ";
                    }
                    $idList = implode(',', $chunk);
                    DB::update("UPDATE product_variants
                        SET allocated_qty = CASE id {$cases} END
                        WHERE id IN ({$idList})");
                }
            }

            $purchasing->update(['status' => 'arrived']);

            // ── Step 6: recalculate ONLY orders whose totals changed ────
            // pending->arrived doesn't change a total (still counted), so we
            // only recalc orders that had a sold_out or partial-split item.
            $promoService    = app(\App\Services\PromoService::class);
            $recalcOrderIds  = array_keys($ordersNeedingRecalc);

            foreach (array_chunk($recalcOrderIds, 300) as $orderIdChunk) {
                $orders = \App\Models\Order::with(['items.product', 'items.variant', 'customer', 'shippingArea'])
                    ->whereIn('id', $orderIdChunk)
                    ->get();

                foreach ($orders as $affectedOrder) {
                    $calc = $promoService->recalculate($affectedOrder);
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