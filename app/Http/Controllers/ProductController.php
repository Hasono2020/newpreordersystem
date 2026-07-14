<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    use \App\Traits\HandlesXlsx;
    public function index(Request $request)
    {
        $perPage = in_array((int)$request->per_page, [20, 50, 100, 200]) ? (int)$request->per_page : 20;
        // Order count should reflect the staff's own scope (own_data staff)
        $uid = \Illuminate\Support\Facades\Auth::user()->isOwnDataOnly()
            ? \Illuminate\Support\Facades\Auth::id() : null;
        $query   = Product::with('trip', 'supplier')->withCount([
            'orderItems' => fn($q) => $uid
                ? $q->whereHas('order', fn($o) => $o->where('created_by', $uid))
                : $q,
        ]);

        if ($request->trip_id) {
            $query->where('trip_id', $request->trip_id);
        }
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('product_code', 'like', '%'.$request->search.'%')
                  ->orWhere('brand', 'like', '%'.$request->search.'%');
            });
        }

        $products = $query->orderBy('product_code')->paginate($perPage)->withQueryString();
        $trips    = Trip::orderByDesc('id')->get();
        return view('products.index', compact('products', 'trips', 'perPage'));
    }

    public function create(Request $request)
    {
        $trips = Trip::whereIn('status', ['open', 'purchasing'])->orderByDesc('id')->get();
        $selectedTrip = $request->trip_id ? Trip::find($request->trip_id) : null;
        // Preload suppliers (small list) so the picker filters instantly with no API calls
        $suppliers = \App\Models\Supplier::orderBy('name')->get(['id', 'name', 'country', 'phone']);
        return view('products.create', compact('trips', 'selectedTrip', 'suppliers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'trip_id'          => 'required|exists:trips,id',
            'supplier_id'      => 'required|exists:suppliers,id',
            'sku'              => 'nullable|string|max:100',
            'product_code' => [
                'required', 'string', 'max:50',
                \Illuminate\Validation\Rule::unique('products')->where('trip_id', $request->trip_id),
            ],
            'brand'            => 'nullable|string|max:100',
            'price'            => 'required|numeric|min:0',
            'weight_gram'      => 'required|integer|min:1',
            'notes'            => 'nullable|string',
            'image'            => 'nullable|image|max:5120',
            'variants'         => 'nullable|array',
            'variants.*.color' => 'nullable|string|max:50',
            'variants.*.size'  => 'nullable|string|max:20',
            'variants.*.price_adjustment' => 'nullable|numeric',
        ], [
            'product_code.unique' => 'This product code is already used by another product. Each product code must be unique.',
        ]);

        $data['excluded_from_promo'] = $request->boolean('excluded_from_promo');

        // Require at least one real variant (a row with a color and/or size).
        $hasVariant = collect($request->input('variants', []))
            ->contains(fn($v) => !empty(trim($v['color'] ?? '')) || !empty(trim($v['size'] ?? '')));
        if (!$hasVariant) {
            return back()
                ->withInput()
                ->with('error', 'Please add at least one variant (color and/or size) before creating the product.');
        }

        if ($request->hasFile('image')) {
            $data['image'] = $this->resizeAndStoreImage($request->file('image'));
        }

        $product = Product::create($data);

        if (!empty($data['variants'])) {
            $seen = [];       // track color|size combos already added in this submission
            $skipped = 0;
            foreach ($data['variants'] as $v) {
                if (!empty($v['color']) || !empty($v['size'])) {
                    $key = strtolower(trim($v['color'] ?? '')) . '|' . strtolower(trim($v['size'] ?? ''));
                    if (isset($seen[$key])) { $skipped++; continue; } // duplicate within the form — skip
                    $seen[$key] = true;

                    $product->variants()->create([
                        'color' => $v['color'] ?? null,
                        'size' => $v['size'] ?? null,
                        'price_adjustment' => $v['price_adjustment'] ?? 0,
                    ]);
                }
            }
        }

        $msg = 'Product created.';
        if (!empty($skipped)) {
            $msg .= " {$skipped} duplicate variant(s) were skipped.";
        }
        return redirect()->route('products.show', $product)->with('success', $msg);
    }

    public function show(Product $product)
    {
        // Staff with own_data scope should only see order items from orders THEY created
        $product->load([
            'variants',
            'orderItems' => function ($q) {
                if (\Illuminate\Support\Facades\Auth::user()->isOwnDataOnly()) {
                    $q->whereHas('order', fn($o) => $o->where('created_by', \Illuminate\Support\Facades\Auth::id()));
                }
            },
            'orderItems.order.customer',
            'trip',
            'supplier',
        ]);
        return view('products.show', compact('product'));
    }

    public function edit(Product $product)
    {
        $trips = Trip::whereIn('status', ['open', 'purchasing'])->orderByDesc('id')->get();
        $product->load('variants', 'supplier');
        return view('products.edit', compact('product', 'trips'));
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'trip_id'      => 'required|exists:trips,id',
            'sku'          => 'nullable|string|max:100',
            'product_code' => [
                'required', 'string', 'max:50',
                \Illuminate\Validation\Rule::unique('products')
                    ->where('trip_id', $request->trip_id)
                    ->ignore($product->id),
            ],
            'brand'        => 'nullable|string|max:100',
            'supplier_id'  => 'required|exists:suppliers,id',
            'price'        => 'required|numeric|min:0',
            'weight_gram'  => 'required|integer|min:1',
            'notes'        => 'nullable|string',
            'status'       => 'required|in:active,closed,arrived',
            'image'        => 'nullable|image|max:5120',
        ], [
            'product_code.unique' => 'This product code is already used by another product. Each product code must be unique.',
        ]);

        $data['excluded_from_promo'] = $request->boolean('excluded_from_promo');

        if ($request->hasFile('image')) {
            if ($product->image) Storage::disk('public')->delete($product->image);
            $data['image'] = $this->resizeAndStoreImage($request->file('image'));
        }

        $oldPrice = $product->price;
        $product->update($data);
        $newPrice = $product->fresh()->price;

        // If price changed, update all order items in open trips and recalc affected orders
        if ((float)$oldPrice !== (float)$newPrice) {
            $this->syncProductPriceToOpenOrders($product, $newPrice);
        }

        return redirect()->route('products.show', $product)->with('success', 'Product updated.');
    }

    public function bulkDestroy(Request $request)
    {
        $this->adminOnly('bulk delete products');

        $request->validate([
            'action'      => 'required|in:selected,no_orders',
            'product_ids' => 'required_if:action,selected|array',
        ]);

        $query = Product::query();

        if ($request->action === 'selected') {
            $query->whereIn('id', $request->product_ids ?? []);
        } elseif ($request->action === 'no_orders') {
            $query->whereDoesntHave('orderItems');
        }

        $products   = $query->with('variants')->get();
        $productIds = $products->pluck('id');
        $variantIds = $products->flatMap(fn($p) => $p->variants->pluck('id'));
        $imagePaths = $products->pluck('image')->filter()->values(); // collect BEFORE rows are gone
        $deleted    = $products->count();

        \DB::transaction(function () use ($productIds, $variantIds, $deleted) {
            if ($variantIds->isNotEmpty()) {
                \App\Models\PurchaseOrderItem::whereIn('product_variant_id', $variantIds)->delete();
            }
            \App\Models\PurchaseOrderItem::whereIn('product_id', $productIds)->delete();
            \DB::table('order_items')->whereIn('product_id', $productIds)->delete();
            \DB::table('product_variants')->whereIn('product_id', $productIds)->delete();
            \DB::table('products')->whereIn('id', $productIds)->delete();
        });

        // Delete image files only AFTER the transaction committed successfully,
        // so a rolled-back delete never leaves products without their images.
        if ($imagePaths->isNotEmpty()) {
            \Storage::disk('public')->delete($imagePaths->all());
        }

        return redirect()->route('products.index', request()->only('trip_id', 'search'))
            ->with('success', "Deleted {$deleted} product(s).");
    }

    public function destroy(Product $product)
    {
        $this->adminOnly('delete products');
        // Must delete in order: purchase_order_items → order_items → variants → product
        // to avoid foreign key constraint violations
        \DB::transaction(function () use ($product) {
            $variantIds = $product->variants()->pluck('id');

            // Remove purchase order items referencing these variants or this product
            \App\Models\PurchaseOrderItem::whereIn('product_variant_id', $variantIds)
                ->orWhere('product_id', $product->id)
                ->delete();

            // Remove order items referencing these variants or this product
            \App\Models\OrderItem::whereIn('product_variant_id', $variantIds)
                ->orWhere('product_id', $product->id)
                ->delete();

            // Delete variants
            $product->variants()->delete();

            // Delete product image from storage
            if ($product->image) {
                \Storage::disk('public')->delete($product->image);
            }

            $product->delete();
        });

        return redirect(session('list_url.products', route('products.index')))->with('success', 'Product deleted.');
    }

    // Manage variants separately
    public function importTemplate()
    {
        return $this->streamXlsx('product_import_template.xlsx', [
            ['Trip', 'Code', 'SKU', 'Brand', 'Supplier', 'Price', 'Weight (gram)', 'Excluded from Promo', 'Status', 'Color', 'Size', 'Price Adjustment', 'Supplier Stock'],
            // One row per variant. Same Code on multiple rows = same product, different variants.
            // Excluded from Promo: yes / no    Status: active / closed
            ['Testing Trip', 'NA_01', '', 'Brand X', 'Shein', 250000, 30000, 'no', 'active', 'BLACK', 'L',  0, 25],
            ['Testing Trip', 'NA_01', '', 'Brand X', 'Shein', 250000, 30000, 'no', 'active', 'BLACK', 'XL', 0, 20],
            ['Testing Trip', 'NA_01', '', 'Brand X', 'Shein', 250000, 30000, 'no', 'active', 'BLACK', 'S',  0, 15],
            ['Testing Trip', 'NA_01', '', 'Brand X', 'Shein', 250000, 30000, 'no', 'active', 'WHITE', 'S',  0, 20],
            ['Testing Trip', 'NA_01', '', 'Brand X', 'Shein', 250000, 30000, 'no', 'active', 'WHITE', 'M',  0, 18],
            ['Testing Trip', 'NZ_01', '', 'Brand Y', 'Uniqlo', 500000, 40000, 'no', 'active', '', '', 0, 0],
        ]);
    }

    public function importCsv(Request $request)
    {
        $request->validate(['file' => 'required|file|max:10240']);

        $rows = $this->readXlsx($request->file('file')->getRealPath());
        if (empty($rows)) {
            return back()->with('error', 'Could not read the file. Make sure it is a valid .xlsx file.');
        }

        array_shift($rows); // remove header

        // Remove completely blank rows
        $rows = array_values(array_filter($rows, fn($r) =>
            !empty(trim((string)($r[0] ?? ''))) || !empty(trim((string)($r[1] ?? '')))
        ));

        if (empty($rows)) {
            return back()->with('error', 'The file has no data rows.');
        }

        // ── Pre-load trips and suppliers into memory ──────────────────────
        $trips = \App\Models\Trip::all()->mapWithKeys(fn($t) => [strtolower(trim($t->name)) => $t]);
        $suppliers = \App\Models\Supplier::all()->mapWithKeys(fn($s) => [strtolower(trim($s->name)) => $s]);

        // ── Full validation pass — ALL rows checked before any insert ─────
        $errors    = [];
        $seenCodes = []; // code → first row's trip (to detect cross-trip duplicates in file)
        $codeHasVariant = []; // code → bool (any row for this code has color/size)
        $codeFirstLine  = []; // code → first line number, for the error message

        foreach ($rows as $i => $row) {
            $lineNum  = $i + 2;
            $tripName = trim((string)($row[0] ?? ''));
            $code     = strtoupper(trim((string)($row[1] ?? '')));

            if (empty($tripName)) { $errors[] = "Row {$lineNum}: Trip is required."; continue; }
            if (empty($code))     { $errors[] = "Row {$lineNum}: Product Code is required."; continue; }

            // Track whether this code has at least one variant (color/size) anywhere in the file
            $rowColor = trim((string)($row[9] ?? ''));
            $rowSize  = trim((string)($row[10] ?? ''));
            if (!isset($codeHasVariant[$code])) {
                $codeHasVariant[$code] = false;
                $codeFirstLine[$code]  = $lineNum;
            }
            if ($rowColor !== '' || $rowSize !== '') {
                $codeHasVariant[$code] = true;
            }

            // Trip must exist
            $tripKey = strtolower($tripName);
            $trip = $trips->get($tripKey) ?? $trips->first(fn($t) => str_contains(strtolower($t->name), $tripKey) || str_contains($tripKey, strtolower($t->name)));
            if (!$trip) {
                $errors[] = "Row {$lineNum} ({$code}): Trip '{$tripName}' not found.";
                continue;
            }

            // If same code seen before in this file, must be same trip (adding a variant)
            if (isset($seenCodes[$code])) {
                if ($seenCodes[$code] !== $trip->id) {
                    $errors[] = "Row {$lineNum} ({$code}): Code '{$code}' used in multiple trips — each code must belong to one trip.";
                }
                continue; // same code = same product, adding a variant — OK
            }
            $seenCodes[$code] = $trip->id;
            // Note: if code already exists in DB, import will add variants to it (not block)
        }

        // Every product must have at least one variant. A code with no variant row in the
        // file is only allowed if that product already exists in the DB WITH variants.
        foreach ($codeHasVariant as $code => $hasVariant) {
            if ($hasVariant) continue;
            $tripId = $seenCodes[$code] ?? null;
            $existingHasVariant = $tripId
                ? \App\Models\Product::where('product_code', $code)->where('trip_id', $tripId)
                    ->whereHas('variants')->exists()
                : false;
            if (!$existingHasVariant) {
                $line = $codeFirstLine[$code] ?? '?';
                $errors[] = "Row {$line} ({$code}): Product must have at least one variant — fill in WARNA (color) and/or SIZE.";
            }
        }

        if (!empty($errors)) {
            return back()->with('import_errors', $errors)
                         ->with('error', count($errors) . ' error(s) found — fix all issues before importing:');
        }

        // ── Import pass ────────────────────────────────────────────────────
        $imported     = 0;
        $variantCount = 0;
        $variantSkipped = 0;
        $newSuppliers = 0;
        $productMap   = []; // code => Product model (created in this import)

        foreach ($rows as $rowIdx => $row) {
            $tripName  = trim((string)($row[0] ?? ''));
            $code      = strtoupper(trim((string)($row[1] ?? '')));
            $sku       = trim((string)($row[2] ?? ''));
            $brand     = trim((string)($row[3] ?? ''));
            $suppName  = trim((string)($row[4] ?? ''));
            $price     = (float)($row[5] ?? 0);
            $weight    = (int)($row[6] ?? 0);
            $excluded  = strtolower(trim((string)($row[7] ?? ''))) === 'yes';
            $status    = trim((string)($row[8] ?? 'active'));
            $color     = trim((string)($row[9] ?? ''));
            $size      = trim((string)($row[10] ?? ''));
            $priceAdj  = (float)($row[11] ?? 0);
            $suppStock = (int)($row[12] ?? 0);

            if (empty($code)) continue;

            $tripKey = strtolower($tripName);
            $trip = $trips->get($tripKey) ?? $trips->first(fn($t) => str_contains(strtolower($t->name), $tripKey));

            // Resolve supplier (auto-create if new)
            $supplierId = null;
            if ($suppName) {
                $suppKey = strtolower($suppName);
                $supplier = $suppliers->get($suppKey) ?? $suppliers->first(fn($s) => str_contains(strtolower($s->name), $suppKey));
                if (!$supplier) {
                    $supplier = \App\Models\Supplier::create(['name' => $suppName, 'is_active' => true]);
                    $suppliers->put($suppKey, $supplier);
                    $newSuppliers++;
                }
                $supplierId = $supplier->id;
            }

            // Find existing product in this import batch, or in DB, or create new
            if (isset($productMap[$code])) {
                $product = $productMap[$code]; // already handled this code in this file
            } else {
                // Check if product already exists in DB for this trip
                $existing = \App\Models\Product::where('product_code', $code)
                    ->where('trip_id', $trip->id)->first();

                if ($existing) {
                    // Update product info with latest data from file
                    $existing->update(array_filter([
                        'sku'                 => $sku   ?: $existing->sku,
                        'brand'               => $brand ?: $existing->brand,
                        'supplier_id'         => $supplierId ?: $existing->supplier_id,
                        'price'               => $price  ?: $existing->price,
                        'weight_gram'         => $weight ?: $existing->weight_gram,
                        'excluded_from_promo' => $excluded,
                        'status'              => in_array($status, ['active','closed','arrived']) ? $status : $existing->status,
                    ]));
                    $product = $existing;
                    $imported++; // count as updated
                } else {
                    $product = \App\Models\Product::create([
                        'trip_id'             => $trip->id,
                        'product_code'        => $code,
                        'sku'                 => $sku   ?: null,
                        'brand'               => $brand ?: null,
                        'supplier_id'         => $supplierId,
                        'price'               => $price,
                        'weight_gram'         => $weight,
                        'excluded_from_promo' => $excluded,
                        'status'              => in_array($status, ['active','closed','arrived']) ? $status : 'active',
                    ]);
                    $imported++;
                }
                $productMap[$code] = $product;
            }

            // Add variant if color or size present, skip if exact same variant already exists
            if ($color || $size) {
                $variantExists = $product->variants()
                    ->whereRaw('LOWER(COALESCE(color,"")) = ?', [strtolower($color)])
                    ->whereRaw('LOWER(COALESCE(size,"")) = ?', [strtolower($size)])
                    ->exists();

                if (!$variantExists) {
                    $product->variants()->create([
                        'color'            => $color    ?: null,
                        'size'             => $size     ?: null,
                        'price_adjustment' => $priceAdj,
                        'supplier_stock'   => $suppStock,
                        'allocated_qty'    => 0,
                    ]);
                    $variantCount++;
                } else {
                    $variantSkipped++;
                }
            }
        }

        $msg = "✓ Imported/updated {$imported} product(s) with {$variantCount} new variant(s).";
        if ($variantSkipped) $msg .= " {$variantSkipped} variant(s) already existed and were skipped.";
        if ($newSuppliers) $msg .= " {$newSuppliers} new supplier(s) auto-created.";
        return redirect()->route('products.index')->with('success', $msg);
    }
    public function export(Request $request)
    {
        $query = Product::with('trip', 'supplier', 'variants');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $products = $query->orderBy('product_code')->get();

        $header = ['trip','product_code','sku','brand','supplier','price','weight_gram','excluded_from_promo','status','color','size','price_adjustment','supplier_stock'];
        $rows   = [$header];

        foreach ($products as $p) {
            $base = [
                $p->trip->name,
                $p->product_code ?? '',
                $p->sku ?? '',
                $p->brand ?? '',
                $p->supplier?->name ?? '',
                $p->price,
                $p->weight_gram,
                $p->excluded_from_promo ? 'yes' : 'no',
                $p->status,
            ];

            if ($p->variants->isEmpty()) {
                // No variants — one row, blank variant columns
                $rows[] = array_merge($base, ['', '', 0, 0]);
            } else {
                foreach ($p->variants as $i => $v) {
                    if ($i === 0) {
                        // First variant row has full product info
                        $rows[] = array_merge($base, [$v->color ?? '', $v->size ?? '', $v->price_adjustment, $v->supplier_stock]);
                    } else {
                        // Subsequent variant rows — only code + variant columns, rest blank
                        // 13 cols: trip, product_code, sku, brand, supplier, price, weight, excluded, status, color, size, price_adj, supplier_stock
                        $rows[] = [
                            '', $p->product_code ?? '', '', '', '', '', '', '', '',
                            $v->color ?? '', $v->size ?? '', $v->price_adjustment, $v->supplier_stock,
                        ];
                    }
                }
            }
        }

        return $this->streamXlsx('products_export.xlsx', $rows);
    }

    /** AJAX: check if product code is already taken within a trip */
    public function checkCode(Request $request)
    {
        $code      = strtoupper(trim($request->code ?? ''));
        $excludeId = $request->exclude;
        $tripId    = $request->trip_id;

        if (!$code) return response()->json(['exists' => false]);

        $query = Product::where('product_code', $code);
        if ($tripId)    $query->where('trip_id', $tripId);
        if ($excludeId) $query->where('id', '!=', $excludeId);

        $product = $query->with('trip')->first();

        if ($product) {
            return response()->json([
                'exists'       => true,
                'product_code' => $product->product_code,
                'trip_name'    => $product->trip->name,
            ]);
        }

        return response()->json(['exists' => false]);
    }

    public function storeVariant(Request $request, Product $product)
    {
        $data = $request->validate([
            'color' => 'nullable|string|max:50',
            'size' => 'nullable|string|max:20',
            'price_adjustment' => 'nullable|numeric',
        ]);

        // Block duplicate color+size combos (case-insensitive), matching import behaviour
        $color = trim($data['color'] ?? '');
        $size  = trim($data['size'] ?? '');
        $exists = $product->variants()
            ->whereRaw('LOWER(COALESCE(color,"")) = ?', [strtolower($color)])
            ->whereRaw('LOWER(COALESCE(size,"")) = ?', [strtolower($size)])
            ->exists();
        if ($exists) {
            return back()->with('error', 'That color/size variant already exists for this product.');
        }

        $product->variants()->create($data);
        return back()->with('success', 'Variant added.');
    }

    public function updateVariant(Request $request, Product $product, ProductVariant $variant)
    {
        $data = $request->validate([
            'color' => 'nullable|string|max:50',
            'size' => 'nullable|string|max:20',
            'price_adjustment' => 'nullable|numeric',
            'supplier_stock' => 'nullable|integer|min:0',
        ]);
        $variant->update($data);
        return back()->with('success', 'Variant updated.');
    }

    public function destroyVariant(Product $product, ProductVariant $variant)
    {
        // Block if any order items or purchase order items reference this variant
        $orderItemCount = $variant->orderItems()->count();
        if ($orderItemCount > 0) {
            return back()->with('error', "Cannot delete this variant — it has {$orderItemCount} order item(s) referencing it. Remove those orders first.");
        }

        $poItemCount = \App\Models\PurchaseOrderItem::where('product_variant_id', $variant->id)->count();
        if ($poItemCount > 0) {
            return back()->with('error', "Cannot delete this variant — it is referenced in {$poItemCount} purchase order item(s).");
        }

        $variant->delete();
        return back()->with('success', 'Variant removed.');
    }

    /**
     * Merge a duplicate variant into another variant of the SAME product.
     * All order items and purchase-order items pointing at the duplicate are
     * reassigned to the survivor, allocated stock is summed onto the survivor,
     * the duplicate is deleted, and affected orders are recalculated.
     */
    public function mergeVariant(Request $request, Product $product, ProductVariant $variant)
    {
        if (!\Illuminate\Support\Facades\Auth::user()->hasPermission('products.edit')) {
            abort(403, 'You do not have permission to merge variants.');
        }

        $data = $request->validate([
            'survivor_id' => 'required|integer',
        ]);

        // The survivor must belong to the same product (can't merge across products)
        $survivor = ProductVariant::where('id', $data['survivor_id'])
            ->where('product_id', $product->id)
            ->first();

        if (!$survivor) {
            return back()->with('error', 'The variant to keep was not found on this product.');
        }
        if ($survivor->id === $variant->id) {
            return back()->with('error', 'Cannot merge a variant into itself.');
        }

        $promoService = app(\App\Services\PromoService::class);

        // Collect affected orders BEFORE the move, so we can recalc them after
        $affectedOrders = \App\Models\OrderItem::where('product_variant_id', $variant->id)
            ->with('order:id,customer_id,trip_id')
            ->get()
            ->pluck('order')
            ->filter()
            ->unique('id');

        \DB::transaction(function () use ($variant, $survivor) {
            // Move order items
            \App\Models\OrderItem::where('product_variant_id', $variant->id)
                ->update(['product_variant_id' => $survivor->id]);

            // Move purchase-order items
            \App\Models\PurchaseOrderItem::where('product_variant_id', $variant->id)
                ->update(['product_variant_id' => $survivor->id]);

            // Sum allocated stock onto the survivor (supplier_stock kept as survivor's own)
            $survivor->allocated_qty = ($survivor->allocated_qty ?? 0) + ($variant->allocated_qty ?? 0);
            $survivor->save();

            // Delete the now-empty duplicate
            $variant->delete();
        });

        // Recalc each affected customer's combined shipping/promo for the trip
        $seen = [];
        foreach ($affectedOrders as $order) {
            $key = $order->customer_id . '-' . $order->trip_id;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $promoService->recalcCustomerShipping($order->customer_id, $order->trip_id);
        }

        \App\Models\ActivityLog::record(
            'variant.merged',
            "Merged variant '{$variant->label}' into '{$survivor->label}' on product {$product->product_code}",
            'product',
            $product->id
        );

        return back()->with('success', "Variant '{$variant->label}' merged into '{$survivor->label}'. Orders reassigned.");
    }

    /**
     * When a product's price changes, update unit_price and line_total on all
     * order items for this product that belong to orders in open trips,
     * then recalculate shipping/promo for each affected customer.
     */
    private function syncProductPriceToOpenOrders(Product $product, float $newPrice): void
    {
        // Get all order items for this product whose order is in an open trip
        $affectedItems = \App\Models\OrderItem::where('product_id', $product->id)
            ->whereHas('order.trip', fn($q) => $q->where('status', 'open'))
            ->with('order:id,customer_id,trip_id,order_number')
            ->get();

        if ($affectedItems->isEmpty()) return;

        $promoService = app(\App\Services\PromoService::class);
        $seen = []; // track customer+trip pairs already recalculated
        $affectedOrderNumbers = $affectedItems->pluck('order.order_number')->unique()->filter()->values();

        \DB::transaction(function () use ($affectedItems, $newPrice) {
            foreach ($affectedItems as $item) {
                // Recalculate line_total using new price + existing variant price adjustment
                $variantAdjustment = $item->variant?->price_adjustment ?? 0;
                $effectivePrice    = $newPrice + $variantAdjustment;

                $item->update([
                    'unit_price' => $effectivePrice,
                    'line_total' => $effectivePrice * $item->quantity,
                ]);
            }
        });

        // Recalc shipping/promo per customer+trip, then recalc payment status per order
        $affectedOrderIds = $affectedItems->pluck('order_id')->unique();

        foreach ($affectedItems as $item) {
            $key = $item->order->customer_id . '-' . $item->order->trip_id;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $promoService->recalcCustomerShipping($item->order->customer_id, $item->order->trip_id);
        }

        // Re-derive payment status from actual payments vs updated totals
        \App\Models\Order::whereIn('id', $affectedOrderIds)->with('payments')->get()
            ->each(function ($order) {
                $payments = $order->payments->filter(fn($p) => $p->voided_at === null);
                $paid     = $payments->where('type', '!=', 'refund')->sum('amount')
                          - $payments->where('type', 'refund')->sum('amount');
                $status   = $paid <= 0 ? 'unpaid'
                    : ($paid >= $order->total_amount ? 'paid' : 'partial');
                $order->update(['deposit_paid' => max(0, $paid), 'payment_status' => $status]);
            });

        // Log the bulk price sync
        $orderCount = $affectedOrderNumbers->count();
        $sample     = $affectedOrderNumbers->take(3)->implode(', ');
        if ($orderCount > 3) $sample .= ' …';

        \App\Models\ActivityLog::record(
            'product.price_synced',
            "Price updated for {$product->product_code} → Rp " . number_format($newPrice, 0, ',', '.') .
                ". Auto-updated {$orderCount} order item(s) in open trips: {$sample}",
            'product',
            $product->id,
            ['price' => ['old' => $product->getOriginal('price'), 'new' => $newPrice]]
        );
    }

    /**
     * Resize image to max 800×800px and store as JPEG ≤200KB.
     * Uses GD (available on most shared hosting).
     */
    private function resizeAndStoreImage(\Illuminate\Http\UploadedFile $file): string
    {
        $maxDim  = 800;   // max width or height in pixels
        $quality = 82;    // JPEG quality start (will reduce if still too big)
        $maxBytes = 200 * 1024; // 200 KB target

        $mime = $file->getMimeType();
        $src  = $file->getRealPath();

        // Load source image via GD
        $srcImg = match(true) {
            str_contains($mime, 'jpeg') || str_contains($mime, 'jpg') => imagecreatefromjpeg($src),
            str_contains($mime, 'png')  => imagecreatefrompng($src),
            str_contains($mime, 'webp') => imagecreatefromwebp($src),
            str_contains($mime, 'gif')  => imagecreatefromgif($src),
            default                      => imagecreatefromjpeg($src),
        };

        if (!$srcImg) {
            // Fallback: just store original
            return $file->store('products', 'public');
        }

        $origW = imagesx($srcImg);
        $origH = imagesy($srcImg);

        // Calculate new dimensions keeping aspect ratio
        if ($origW <= $maxDim && $origH <= $maxDim) {
            $newW = $origW;
            $newH = $origH;
        } elseif ($origW >= $origH) {
            $newW = $maxDim;
            $newH = (int) round($origH * $maxDim / $origW);
        } else {
            $newH = $maxDim;
            $newW = (int) round($origW * $maxDim / $origH);
        }

        $dstImg = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
        imagefill($dstImg, 0, 0, $transparent);

        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($srcImg);

        // Save to temp buffer and reduce quality until under maxBytes
        $filename = 'products/' . uniqid('img_', true) . '.jpg';
        do {
            ob_start();
            imagejpeg($dstImg, null, $quality);
            $data = ob_get_clean();
            $quality -= 5;
        } while (strlen($data) > $maxBytes && $quality > 20);

        imagedestroy($dstImg);

        Storage::disk('public')->put($filename, $data);
        return $filename;
    }
}