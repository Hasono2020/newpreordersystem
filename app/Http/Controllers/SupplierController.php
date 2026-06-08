<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $perPage = in_array((int)$request->per_page, [20, 50, 100, 200]) ? (int)$request->per_page : 20;
        $query   = Supplier::withCount('products', 'purchaseOrders');
        if ($request->search) {
            $query->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('contact_person', 'like', '%'.$request->search.'%');
        }
        $suppliers = $query->orderBy('name')->paginate($perPage)->withQueryString();
        return view('suppliers.index', compact('suppliers', 'perPage'));
    }

    public function create()
    {
        return view('suppliers.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone'          => 'nullable|string|max:50',
            'country'        => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
            'is_active'      => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $supplier = Supplier::create($data);

        if ($request->expectsJson()) return response()->json($supplier);
        return redirect()->route('suppliers.index')->with('success', 'Supplier added.');
    }

    public function show(Supplier $supplier)
    {
        $supplier->load(['products.trip', 'purchaseOrders.trip']);
        return view('suppliers.show', compact('supplier'));
    }

    public function edit(Supplier $supplier)
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone'          => 'nullable|string|max:50',
            'country'        => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
            'is_active'      => 'boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $supplier->update($data);
        return redirect()->route('suppliers.show', $supplier)->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier)
    {
        // Nullify FK on products and POs before deleting
        $supplier->products()->update(['supplier_id' => null]);
        $supplier->purchaseOrders()->update(['supplier_id' => null]);
        $supplier->delete();
        return redirect()->route('suppliers.index')->with('success', 'Supplier deleted.');
    }

    /** AJAX: search suppliers */
    public function search(Request $request)
    {
        $q = $request->q ?? '';
        $suppliers = Supplier::where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('country', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'contact_person', 'phone', 'country']);
        return response()->json($suppliers);
    }

    /** AJAX: quick-create supplier */
    public function quickStore(Request $request)
    {
        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'country' => 'nullable|string|max:100',
            'phone'   => 'nullable|string|max:50',
        ]);
        $supplier = Supplier::create(array_merge($data, ['is_active' => true]));
        return response()->json($supplier);
    }

    public function bulkDestroy(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!empty($ids)) {
            // Unlink products before deleting
            \App\Models\Product::whereIn('supplier_id', $ids)->update(['supplier_id' => null]);
            \App\Models\Supplier::whereIn('id', $ids)->delete();
        }
        return redirect()->route('suppliers.index')->with('success', count($ids) . ' supplier(s) deleted.');
    }
}