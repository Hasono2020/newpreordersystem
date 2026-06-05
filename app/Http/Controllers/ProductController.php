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
            ['trip','name','product_code','sku','brand','supplier','price','weight_gram','excluded_from_promo','notes'],
            ['China June 2026','Kemeja Floral','NA_05','','Brand X','Supplier A',120000,330,'no','Cotton material'],
            ['China June 2026','Celana Jeans','NZ_05','','','Supplier B',250000,500,'yes',''],
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
        foreach ($rows as $rowIdx => $row) {
            $lineNum  = $rowIdx + 2;
            $tripName = trim($row[0] ?? '');
            $name     = trim($row[1] ?? '');
            $code     = strtoupper(trim($row[2] ?? ''));

            if (empty($name) && empty($tripName)) continue;

            $issues = [];
            if (empty($tripName)) $issues[] = 'Trip is required';
            if (empty($name))     $issues[] = 'Product name is required';

            if ($code && \App\Models\Product::where('product_code', $code)->exists()) {
                $issues[] = "Product code '{$code}' already exists";
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

        // ── All valid — proceed ────────────────────────────────────────
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

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
            $notes        = trim($row[9] ?? '');

            if (empty($name)) continue;

            $trip = \App\Models\Trip::where('name', 'like', '%'.$tripName.'%')->first();
            if (!$trip) { $errors[] = "Row {$lineNum} ({$name}): trip '{$tripName}' not found."; $skipped++; continue; }

            $supplierId = null;
            if ($supplierName) {
                $supplier   = \App\Models\Supplier::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($supplierName).'%'])->first();
                $supplierId = $supplier?->id;
            }

            \App\Models\Product::create([
                'trip_id'             => $trip->id,
                'name'                => $name,
                'product_code'        => $code ?: null,
                'sku'                 => $sku ?: null,
                'brand'               => $brand ?: null,
                'supplier_id'         => $supplierId,
                'price'               => $price,
                'weight_gram'         => $weight,
                'excluded_from_promo' => $excluded,
                'notes'               => $notes ?: null,
                'status'              => 'active',
            ]);
            $imported++;
        }

        $msg = "✓ Imported {$imported} product(s).";
        if ($skipped) $msg .= " {$skipped} skipped.";
        if ($errors)  $msg .= " Issues: ".implode(' | ', array_slice($errors, 0, 3));
        return redirect()->route('products.index')->with($errors ? 'warning' : 'success', $msg);
    }

    public function export(Request $request)
    {
        $query = Product::with('trip', 'supplier')
            ->withSum(['orderItems as total_ordered' =>
                fn($q) => $q->whereNotIn('status', ['cancelled','sold_out'])], 'quantity');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $products = $query->orderBy('name')->get();

        $rows = [['trip','name','product_code','sku','brand','supplier','price','weight_gram','excluded_from_promo','status']];
        foreach ($products as $p) {
            $rows[] = [$p->trip->name, $p->name,
                $p->product_code ?? '', $p->sku ?? '', $p->brand ?? '',
                $p->supplier?->name ?? '',
                $p->price, $p->weight_gram,
                $p->excluded_from_promo ? 'yes' : 'no',
                $p->status];
        }
        return $this->streamXlsx('products_export.xlsx', $rows);
    }

    private function streamCsv(string $filename, callable $callback) {} // kept for safety, unused

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
