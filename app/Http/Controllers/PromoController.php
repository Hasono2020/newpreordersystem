<?php

namespace App\Http\Controllers;

use App\Models\PromoRule;
use Illuminate\Http\Request;
use App\Models\Trip;

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
            'name'                      => 'required|string|max:255',
            'description'               => 'nullable|string',
            'min_items'                 => 'required|integer|min:1',
            'discount_per_item'         => 'nullable|numeric|min:0',
            'discount_flat'             => 'nullable|numeric|min:0',
            'max_shipping_subsidy'      => 'nullable|numeric|min:0',
            'eligible_customer_types'   => 'nullable|array',
            'eligible_customer_types.*' => 'in:customer,reseller,selected_customer',
            'excluded_product_codes'    => 'nullable|string',
            'trip_id'                   => 'nullable|exists:trips,id',
            'is_active'                 => 'boolean',
        ]);

        $data['eligible_customer_types'] = !empty($data['eligible_customer_types']) ? $data['eligible_customer_types'] : null;
        $data['excluded_product_codes']  = $this->parseCodeList($data['excluded_product_codes'] ?? '');
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
            'name'                      => 'required|string|max:255',
            'description'               => 'nullable|string',
            'min_items'                 => 'required|integer|min:1',
            'discount_per_item'         => 'nullable|numeric|min:0',
            'discount_flat'             => 'nullable|numeric|min:0',
            'max_shipping_subsidy'      => 'nullable|numeric|min:0',
            'eligible_customer_types'   => 'nullable|array',
            'eligible_customer_types.*' => 'in:customer,reseller,selected_customer',
            'excluded_product_codes'    => 'nullable|string',
            'trip_id'                   => 'nullable|exists:trips,id',
            'is_active'                 => 'boolean',
        ]);

        $data['is_active']               = $request->boolean('is_active');
        $data['eligible_customer_types'] = !empty($data['eligible_customer_types']) ? $data['eligible_customer_types'] : null;
        $data['excluded_product_codes']  = $this->parseCodeList($data['excluded_product_codes'] ?? '');
        $promo->update($data);
        return redirect()->route('promos.index')->with('success', 'Promo rule updated.');
    }

    public function destroy(PromoRule $promo)
    {
        $promo->delete();
        return redirect()->route('promos.index')->with('success', 'Promo rule deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            PromoRule::query()->delete();
            return redirect()->route('promos.index')->with('success', 'All promo rules deleted.');
        }
        $ids = $request->input('ids', []);
        if (!empty($ids)) {
            PromoRule::whereIn('id', $ids)->delete();
        }
        return redirect()->route('promos.index')->with('success', count($ids).' promo rule(s) deleted.');
    }

    private function parseCodeList(string $raw): ?array
    {
        $codes = array_filter(array_map(fn($c) => strtoupper(trim($c)), explode(',', $raw)));
        return !empty($codes) ? array_values($codes) : null;
    }
}