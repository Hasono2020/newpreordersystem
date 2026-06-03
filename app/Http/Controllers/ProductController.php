<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('trip')->withCount('orderItems');
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
            'product_code'     => 'nullable|string|max:50',
            'brand'            => 'nullable|string|max:100',
            'price'            => 'required|numeric|min:0',
            'weight_gram'      => 'nullable|integer|min:0',
            'notes'            => 'nullable|string',
            'image'            => 'nullable|image|max:512', // 512KB hard server limit
            'variants'         => 'nullable|array',
            'variants.*.color' => 'nullable|string|max:50',
            'variants.*.size'  => 'nullable|string|max:20',
            'variants.*.price_adjustment' => 'nullable|numeric',
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
        $product->load('variants');
        return view('products.edit', compact('product', 'trips'));
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'trip_id'      => 'required|exists:trips,id',
            'name'         => 'required|string|max:255',
            'sku'          => 'nullable|string|max:100',
            'product_code' => 'nullable|string|max:50',
            'brand'        => 'nullable|string|max:100',
            'price'        => 'required|numeric|min:0',
            'weight_gram'  => 'nullable|integer|min:0',
            'notes'        => 'nullable|string',
            'status'       => 'required|in:active,closed,arrived',
            'image'        => 'nullable|image|max:512',
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
        $product->delete();
        return redirect()->route('products.index')->with('success', 'Product deleted.');
    }

    // Manage variants separately
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
        $variant->delete();
        return back()->with('success', 'Variant removed.');
    }
}
