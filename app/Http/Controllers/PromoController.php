<?php

namespace App\Http\Controllers;

use App\Models\PromoRule;
use App\Models\Trip;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    public function index()
    {
        $promos = PromoRule::with('trip')->latest()->paginate(20);
        return view('promos.index', compact('promos'));
    }

    public function create()
    {
        $trips = Trip::orderByDesc('id')->get();
        return view('promos.create', compact('trips'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'min_items' => 'required|integer|min:1',
            'discount_per_item' => 'nullable|numeric|min:0',
            'discount_flat' => 'nullable|numeric|min:0',
            'max_shipping_subsidy' => 'nullable|numeric|min:0',
            'eligible_customer_types' => 'nullable|array',
            'eligible_customer_types.*' => 'in:customer,reseller,selected_customer',
            'trip_id' => 'nullable|exists:trips,id',
            'is_active' => 'boolean',
        ]);

        $data['eligible_customer_types'] = !empty($data['eligible_customer_types']) ? $data['eligible_customer_types'] : null;
        PromoRule::create($data);
        return redirect()->route('promos.index')->with('success', 'Promo rule created.');
    }

    public function edit(PromoRule $promo)
    {
        $trips = Trip::orderByDesc('id')->get();
        return view('promos.edit', compact('promo', 'trips'));
    }

    public function update(Request $request, PromoRule $promo)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'min_items' => 'required|integer|min:1',
            'discount_per_item' => 'nullable|numeric|min:0',
            'discount_flat' => 'nullable|numeric|min:0',
            'max_shipping_subsidy' => 'nullable|numeric|min:0',
            'eligible_customer_types' => 'nullable|array',
            'eligible_customer_types.*' => 'in:customer,reseller,selected_customer',
            'trip_id' => 'nullable|exists:trips,id',
            'is_active' => 'boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['eligible_customer_types'] = !empty($data['eligible_customer_types']) ? $data['eligible_customer_types'] : null;
        $promo->update($data);
        return redirect()->route('promos.index')->with('success', 'Promo rule updated.');
    }

    public function destroy(PromoRule $promo)
    {
        $promo->delete();
        return redirect()->route('promos.index')->with('success', 'Promo rule deleted.');
    }
}
