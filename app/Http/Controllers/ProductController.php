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
        $query = Product::with('trip', 'supplier')->withCount('orderItems');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $products = $query->latest()->paginate(20)->withQueryString();
        $trips = Trip::orderByDesc('id')->get();
        return view('products.index', compact('products', 'trips'));
    }

    public function create(Request $request)
    {
        $trips = Trip::whereIn('status', ['open', 'purchasing'])->orderByDesc('id')->get();
        $selectedTrip = $request->trip_id ? Trip::find($request->trip_id) : null;
        return view('products.create', compact('trips', 'selectedTrip'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'trip_id'          => 'required|exists:trips,id',
            'name'             => 'required|string|max:255',
            'sku'              => 'nullable|string|max:100',
            'product_code'     => 'nullable|string|max:50|unique:products,product_code',
            'brand'            => 'nullable|string|max:100',
            'price'            => 'required|numeric|min:0',
            'weight_gram'      => 'nullable|integer|min:0',
            'notes'            => 'nullable|string',
            'image'            => 'nullable|image|max:512',
            'variants'         => 'nullable|array',
            'variants.*.color' => 'nullable|string|max:50',
            'variants.*.size'  => 'nullable|string|max:20',
            'variants.*.price_adjustment' => 'nullable|numeric',
        ], [
            'product_code.unique' => 'This product code is already used by another product. Each product code must be unique.',
        ]);

        $data['excluded_from_promo'] = $request->boolean('excluded_from_promo');

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = Product::create($data);

        if (!empty($data['variants'])) {
            foreach ($data['variants'] as $v) {
                if (!empty($v['color']) || !empty($v['size'])) {
                    $product->variants()->create([
                        'color' => $v['color'] ?? null,
                        'size' => $v['size'] ?? null,
                        'price_adjustment' => $v['price_adjustment'] ?? 0,
                    ]);
                }
            }
        }

        return redirect()->route('products.show', $product)->with('success', 'Product created.');
    }

    public function show(Product $product)
    {
        $product->load(['variants', 'orderItems.order.customer', 'trip']);
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
            'name'         => 'required|string|max:255',
            'sku'          => 'nullable|string|max:100',
            'product_code' => 'nullable|string|max:50|unique:products,product_code,'.$product->id,
            'brand'        => 'nullable|string|max:100',
            'supplier_id'  => 'required|exists:suppliers,id',
            'price'        => 'required|numeric|min:0',
            'weight_gram'  => 'nullable|integer|min:0',
            'notes'        => 'nullable|string',
            'status'       => 'required|in:active,closed,arrived',
            'image'        => 'nullable|image|max:512',
        ], [
            'product_code.unique' => 'This product code is already used by another product. Each product code must be unique.',
        ]);

        $data['excluded_from_promo'] = $request->boolean('excluded_from_promo');

        if ($request->hasFile('image')) {
            if ($product->image) Storage::disk('public')->delete($product->image);
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product->update($data);
        return redirect()->route('products.show', $product)->with('success', 'Product updated.');
    }

    public function destroy(Product $product)
    {
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

        return redirect()->route('products.index')->with('success', 'Product deleted.');
    }

    // Manage variants separately
    public function importTemplate()
    {
        return $this->streamXlsx('product_import_template.xlsx', [
            ['trip','name','product_code','sku','brand','supplier','price','weight_gram','excluded_from_promo','status','color','size','price_adjustment','supplier_stock'],
            ['China June 2026','Baju','NA_01','','Brand X','Shein',250000,330,'no','active','Black','S',0,25],
            ['','','NA_01','','','','','','','','White','M',0,20],
            ['','','NA_01','','','','','','','','Navy','L',5000,15],
            ['China June 2026','Celana Chino','NZ_01','','','Uniqlo',500000,400,'yes','active','','','',''],
        ]);
    }

    public function importCsv(Request $request)
    {
        $request->validate(['file' => 'required|file|max:5120']);

        $rows = $this->readXlsx($request->file('file')->getRealPath());
        if (empty($rows)) {
            return back()->with('error', 'Could not read the file. Make sure it is a valid .xlsx file.');
        }

        array_shift($rows); // skip header

        // ── Validation pass first ──────────────────────────────────────
        $validationErrors = [];
        $seenCodes        = []; // track codes we're about to create in this import

        foreach ($rows as $rowIdx => $row) {
            $lineNum  = $rowIdx + 2;
            $tripName = trim($row[0] ?? '');
            $name     = trim($row[1] ?? '');
            $code     = strtoupper(trim($row[2] ?? ''));

            // Rows with only variant data (blank name/trip continuing a previous product)
            if (empty($name) && empty($tripName) && !empty($code)) continue;
            if (empty($name) && empty($tripName) && empty($code)) continue;

            $issues = [];
            if (empty($tripName))     $issues[] = 'Trip is required';
            if (empty($name))         $issues[] = 'Product name is required';
            if (empty(trim($row[5] ?? ''))) $issues[] = 'Supplier is required';

            // Duplicate code in DB
            if ($code && \App\Models\Product::where('product_code', $code)->exists()) {
                $issues[] = "Product code '{$code}' already exists in the system";
            }
            // Duplicate code within this import file
            if ($code && isset($seenCodes[$code])) {
                // It's the same product continued — not an error, it's adding variants
            } elseif ($code) {
                $seenCodes[$code] = true;
            }

            if (!empty($issues)) {
                $label = $name ?: "(row {$lineNum})";
                $validationErrors[] = "Row {$lineNum} ({$label}): " . implode(', ', $issues) . '.';
            }
        }

        if (!empty($validationErrors)) {
            return back()->with('import_errors', $validationErrors)
                         ->with('error', 'Import blocked — please fix the following issues before importing:');
        }

        // ── Import pass ────────────────────────────────────────────────
        $imported     = 0;
        $variantCount = 0;
        $newSuppliers = 0;
        $skipped      = 0;
        $errors       = [];
        $productMap   = [];

        foreach ($rows as $rowIdx => $row) {
            $lineNum      = $rowIdx + 2;
            $tripName     = trim($row[0] ?? '');
            $name         = trim($row[1] ?? '');
            $code         = strtoupper(trim($row[2] ?? ''));
            $sku          = trim($row[3] ?? '');
            $brand        = trim($row[4] ?? '');
            $supplierName = trim($row[5] ?? '');
            $price        = (float)($row[6] ?? 0);
            $weight       = (int)($row[7] ?? 0);
            $excluded     = strtolower(trim($row[8] ?? '')) === 'yes';
            $status       = trim($row[9] ?? 'active');
            $color        = trim($row[10] ?? '');
            $size         = trim($row[11] ?? '');
            $priceAdj     = (float)($row[12] ?? 0);
            $suppStock    = (int)($row[13] ?? 0);

            // Continuation row (same product, new variant)
            if (empty($name) && empty($tripName) && !empty($code)) {
                $product = $productMap[$code] ?? null;
                if ($product && ($color || $size)) {
                    $product->variants()->create([
                        'color'          => $color ?: null,
                        'size'           => $size  ?: null,
                        'price_adjustment'=> $priceAdj,
                        'supplier_stock' => $suppStock,
                        'allocated_qty'  => 0,
                    ]);
                    $variantCount++;
                }
                continue;
            }

            if (empty($name)) continue;

            // Find trip
            $trip = \App\Models\Trip::where('name', 'like', '%'.$tripName.'%')->first();
            if (!$trip) {
                $errors[] = "Row {$lineNum} ({$name}): trip '{$tripName}' not found.";
                $skipped++; continue;
            }

            // Find supplier — auto-create if not found
            $supplierId = null;
            if ($supplierName) {
                $supplier = \App\Models\Supplier::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($supplierName).'%'])->first();
                if (!$supplier) {
                    $supplier = \App\Models\Supplier::create(['name' => $supplierName, 'is_active' => true]);
                    $newSuppliers++;
                }
                $supplierId = $supplier->id;
            }

            $product = \App\Models\Product::create([
                'trip_id'             => $trip->id,
                'name'                => $name,
                'product_code'        => $code ?: null,
                'sku'                 => $sku   ?: null,
                'brand'               => $brand ?: null,
                'supplier_id'         => $supplierId,
                'price'               => $price,
                'weight_gram'         => $weight,
                'excluded_from_promo' => $excluded,
                'notes'               => null,
                'status'              => in_array($status, ['active','closed','arrived']) ? $status : 'active',
            ]);

            $imported++;
            if ($code) $productMap[$code] = $product;

            // Create variant from this first row if color/size provided
            if ($color || $size) {
                $product->variants()->create([
                    'color'           => $color ?: null,
                    'size'            => $size  ?: null,
                    'price_adjustment'=> $priceAdj,
                    'supplier_stock'  => $suppStock,
                    'allocated_qty'   => 0,
                ]);
                $variantCount++;
            }
        }

        $msg = "✓ Imported {$imported} product(s) with {$variantCount} variant(s).";
        if ($newSuppliers) $msg .= " {$newSuppliers} new supplier(s) auto-created.";
        if ($skipped) $msg .= " {$skipped} skipped.";
        if ($errors)  $msg .= " Issues: ".implode(' | ', array_slice($errors, 0, 3));
        return redirect()->route('products.index')->with($errors ? 'warning' : 'success', $msg);
    }

    public function export(Request $request)
    {
        $query = Product::with('trip', 'supplier', 'variants');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $products = $query->orderBy('name')->get();

        $header = ['trip','name','product_code','sku','brand','supplier','price','weight_gram','excluded_from_promo','status','color','size','price_adjustment','supplier_stock'];
        $rows   = [$header];

        foreach ($products as $p) {
            $base = [
                $p->trip->name,
                $p->name,
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
                        $rows[] = [
                            '', '', $p->product_code ?? '', '', '', '', '', '', '', '',
                            $v->color ?? '', $v->size ?? '', $v->price_adjustment, $v->supplier_stock,
                        ];
                    }
                }
            }
        }

        return $this->streamXlsx('products_export.xlsx', $rows);
    }

    /** AJAX: check if product code is already taken */
    public function checkCode(Request $request)
    {
        $code      = strtoupper(trim($request->code ?? ''));
        $excludeId = $request->exclude;

        if (!$code) return response()->json(['exists' => false]);

        $query = Product::where('product_code', $code);
        if ($excludeId) $query->where('id', '!=', $excludeId);

        $product = $query->with('trip')->first();

        if ($product) {
            return response()->json([
                'exists'       => true,
                'product_name' => $product->name,
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
}
