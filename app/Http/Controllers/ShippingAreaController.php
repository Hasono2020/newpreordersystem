<?php

namespace App\Http\Controllers;

use App\Models\ShippingArea;
use Illuminate\Http\Request;

class ShippingAreaController extends Controller
{
    use \App\Traits\HandlesXlsx;
    public function index(Request $request)
    {
        $perPage = in_array((int)$request->per_page, [20, 50, 100, 200]) ? (int)$request->per_page : 20;
        $query   = ShippingArea::query();
        if ($request->search) {
            $query->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('province', 'like', '%'.$request->search.'%');
        }
        $areas = $query->orderBy('name')->paginate($perPage)->withQueryString();
        return view('shipping.index', compact('areas', 'perPage'));
    }

    public function show(ShippingArea $shipping)
    {
        $customerCount = \App\Models\Customer::where('default_shipping_area_id', $shipping->id)->count();
        $orderCount    = \App\Models\Order::where('shipping_area_id', $shipping->id)->count();
        // Sample shipping fees
        $samples = [500, 1000, 2000, 3000, 5000, 10000];
        return view('shipping.show', compact('shipping', 'customerCount', 'orderCount', 'samples'));
    }

    public function create()
    {
        return view('shipping.create');
    }

    public function store(Request $request)
    {
        $pricingMode = $request->input('pricing_mode');
        if (!$pricingMode) {
            $pricingMode = $request->filled('flat_fee') ? 'flat' : 'per_kg';
        }

        $data = $request->validate([
            'name'                 => 'required|string|max:255',
            'province'             => 'nullable|string|max:255',
            'pricing_mode'         => 'nullable|in:per_kg,flat',
            'price_per_kg'         => $pricingMode === 'per_kg' ? 'required|numeric|min:0' : 'nullable|numeric|min:0',
            'flat_fee'             => $pricingMode === 'flat'   ? 'required|numeric|min:1' : 'nullable|numeric|min:0',
            'flat_fee_subsidy_cap' => 'nullable|numeric|min:0',
            'is_active'            => 'boolean',
            'notes'                => 'nullable|string',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        if ($pricingMode === 'flat') {
            $data['flat_fee']             = (float) $request->flat_fee;
            $data['flat_fee_subsidy_cap'] = $request->filled('flat_fee_subsidy_cap') ? (float) $request->flat_fee_subsidy_cap : null;
            $data['price_per_kg']         = 0;
        } else {
            $data['flat_fee']             = null;
            $data['flat_fee_subsidy_cap'] = null;
        }

        ShippingArea::create($data);
        return redirect()->route('shipping.index')->with('success', 'Shipping area added.');
    }

    public function edit(ShippingArea $shipping)
    {
        return view('shipping.edit', compact('shipping'));
    }

    public function update(Request $request, ShippingArea $shipping)
    {
        // Infer pricing_mode from submitted fields as a fallback in case the
        // radio button value is missing (e.g. JS toggling hid it from the POST).
        $pricingMode = $request->input('pricing_mode');
        if (!$pricingMode) {
            $pricingMode = $request->filled('flat_fee') ? 'flat' : 'per_kg';
        }

        $data = $request->validate([
            'name'                 => 'required|string|max:255',
            'province'             => 'nullable|string|max:255',
            'pricing_mode'         => 'nullable|in:per_kg,flat',
            'price_per_kg'         => $pricingMode === 'per_kg' ? 'required|numeric|min:0' : 'nullable|numeric|min:0',
            'flat_fee'             => $pricingMode === 'flat'   ? 'required|numeric|min:1' : 'nullable|numeric|min:0',
            'flat_fee_subsidy_cap' => 'nullable|numeric|min:0',
            'is_active'            => 'boolean',
            'notes'                => 'nullable|string',
        ]);
        $data['is_active'] = $request->boolean('is_active');

        $oldPricePerKg = (float) $shipping->price_per_kg;
        $oldFlatFee    = (float) ($shipping->flat_fee ?? 0);

        if ($pricingMode === 'flat') {
            $data['flat_fee']             = (float) $request->flat_fee;
            $data['flat_fee_subsidy_cap'] = $request->filled('flat_fee_subsidy_cap') ? (float) $request->flat_fee_subsidy_cap : null;
            $data['price_per_kg']         = 0;
        } else {
            $data['flat_fee']             = null;
            $data['flat_fee_subsidy_cap'] = null;
        }

        $shipping->update($data);

        // Recalc open orders whenever the effective rate changes —
        // covers both per-kg edits AND switching to/from flat fee.
        $newPricePerKg = (float) $shipping->fresh()->price_per_kg;
        $newFlatFee    = (float) ($shipping->fresh()->flat_fee ?? 0);

        if ($oldPricePerKg !== $newPricePerKg || $oldFlatFee !== $newFlatFee) {
            $this->syncShippingPriceToOpenOrders($shipping);
        }

        return redirect()->route('shipping.index')->with('success', 'Shipping area updated.');
    }

    /**
     * When a shipping area's price_per_kg changes, recalculate shipping fees
     * for all orders using this area that belong to open trips.
     */
    private function syncShippingPriceToOpenOrders(\App\Models\ShippingArea $shipping): void
    {
        $affectedOrders = \App\Models\Order::where('shipping_area_id', $shipping->id)
            ->whereHas('trip', fn($q) => $q->where('status', 'open'))
            ->select('id', 'customer_id', 'trip_id', 'order_number')
            ->get();

        if ($affectedOrders->isEmpty()) return;

        $promoService = app(\App\Services\PromoService::class);
        $seen = [];

        foreach ($affectedOrders as $order) {
            $key = $order->customer_id . '-' . $order->trip_id;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $promoService->recalcCustomerShipping($order->customer_id, $order->trip_id);
        }

        // Re-derive payment status from actual payments vs updated totals
        $affectedOrderIds = $affectedOrders->pluck('id');
        \App\Models\Order::whereIn('id', $affectedOrderIds)->with('payments')->get()
            ->each(function ($order) {
                $payments = $order->payments->filter(fn($p) => $p->voided_at === null);
                $paid     = $payments->where('type', '!=', 'refund')->sum('amount')
                          - $payments->where('type', 'refund')->sum('amount');
                $status   = $paid <= 0 ? 'unpaid'
                    : ($paid >= $order->total_amount ? 'paid' : 'partial');
                $order->update(['deposit_paid' => max(0, $paid), 'payment_status' => $status]);
            });

        // A rate change can leave one order overpaid while another order for
        // the SAME customer in this trip is still short — reallocate any
        // such credit automatically rather than leaving it stranded until
        // someone notices and manually voids/re-records a payment.
        $reallocator = app(\App\Services\CreditReallocationService::class);
        foreach ($seen as $key => $true) {
            [$custId, $tripId] = explode('-', $key, 2);
            $reallocator->reallocate((int) $custId, (int) $tripId);
        }

        // Log the bulk shipping fee sync
        $orderCount = $affectedOrders->count();
        $sample     = $affectedOrders->pluck('order_number')->take(3)->implode(', ');
        if ($orderCount > 3) $sample .= ' …';

        \App\Models\ActivityLog::record(
            'shipping.price_synced',
            "Shipping rate updated for \"{$shipping->name}\" → " . ($shipping->isFlatFee()
                ? 'Flat Rp ' . number_format($shipping->flat_fee, 0, ',', '.')
                : 'Rp ' . number_format($shipping->price_per_kg, 0, ',', '.') . '/kg') .
                ". Auto-recalculated shipping fee on {$orderCount} order(s) in open trips: {$sample}",
            'shipping_area',
            $shipping->id,
            [
                'old' => $shipping->isFlatFee()
                    ? ['type' => 'flat', 'flat_fee' => $shipping->flat_fee]
                    : ['type' => 'per_kg', 'price_per_kg' => $shipping->price_per_kg],
            ]
        );
    }

    public function destroy(ShippingArea $shipping)
    {
        $customerCount = \App\Models\Customer::where('default_shipping_area_id', $shipping->id)->count();
        $orderCount    = \App\Models\Order::where('shipping_area_id', $shipping->id)->count();

        if ($customerCount > 0 || $orderCount > 0) {
            return back()->with('error',
                "Cannot delete \"{$shipping->name}\" — it is still used by {$customerCount} customer(s) and {$orderCount} order(s). " .
                "Reassign them first or use bulk delete to forcefully remove."
            );
        }

        $shipping->delete();
        return redirect()->route('shipping.index')->with('success', 'Deleted.');
    }

    // ── Excel / CSV ──────────────────────────────────────────────────

    /**
     * Download blank import template as CSV
     */
    public function template()
    {
        return $this->streamXlsx('shipping_areas_template.xlsx', [
            ['name', 'province', 'pricing_mode', 'price_per_kg', 'flat_fee', 'flat_fee_subsidy_cap', 'is_active', 'notes'],
            // pricing_mode: per_kg | flat
            // If pricing_mode=flat, fill flat_fee (and optionally flat_fee_subsidy_cap); leave price_per_kg blank.
            // If pricing_mode=per_kg, fill price_per_kg; leave flat_fee blank.
            ['Batam',          'Kepulauan Riau', 'flat',   '',     10000, '', 1, ''],
            ['Jakarta Pusat',  'DKI Jakarta',    'per_kg', 30000,  '',    '', 1, ''],
            ['Surabaya',       'Jawa Timur',     'per_kg', 35000,  '',    '', 1, ''],
        ]);
    }

    public function export()
    {
        $areas = ShippingArea::orderBy('name')->get();
        $rows  = [['name', 'province', 'pricing_mode', 'price_per_kg', 'flat_fee', 'flat_fee_subsidy_cap', 'is_active', 'notes']];
        foreach ($areas as $area) {
            $rows[] = [
                $area->name,
                $area->province,
                $area->isFlatFee() ? 'flat' : 'per_kg',
                $area->price_per_kg,
                $area->flat_fee ?? '',
                $area->flat_fee_subsidy_cap ?? '',
                $area->is_active ? 1 : 0,
                $area->notes,
            ];
        }
        return $this->streamXlsx('shipping_areas_export.xlsx', $rows);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|max:5120']);

        $rows = $this->readXlsx($request->file('file')->getRealPath());
        if (empty($rows)) {
            return back()->with('error', 'Could not read file. Make sure it is a valid .xlsx file.');
        }

        array_shift($rows); // skip header
        $imported = 0;
        $errors   = [];

        foreach ($rows as $row) {
            $name             = trim($row[0] ?? '');
            $province         = trim($row[1] ?? '');
            $pricingMode      = strtolower(trim($row[2] ?? 'per_kg'));
            $pricePerKg       = (float) str_replace(',', '', $row[3] ?? 0);
            $flatFee          = $row[4] !== '' && $row[4] !== null ? (float) str_replace(',', '', $row[4]) : null;
            $flatFeeSubsidy   = $row[5] !== '' && $row[5] !== null ? (float) str_replace(',', '', $row[5]) : null;
            $isActive         = in_array(strtolower((string)($row[6] ?? '1')), ['1','true','yes','active']);
            $notes            = trim($row[7] ?? '');

            if (empty($name)) continue;

            // Infer pricing_mode from data if not explicitly set
            if (!in_array($pricingMode, ['flat', 'per_kg'])) {
                $pricingMode = ($flatFee !== null && $flatFee > 0) ? 'flat' : 'per_kg';
            }

            $attrs = $pricingMode === 'flat'
                ? ['flat_fee' => $flatFee, 'flat_fee_subsidy_cap' => $flatFeeSubsidy, 'price_per_kg' => 0]
                : ['price_per_kg' => $pricePerKg, 'flat_fee' => null, 'flat_fee_subsidy_cap' => null];

            try {
                ShippingArea::updateOrCreate(
                    ['name' => $name],
                    array_merge($attrs, [
                        'province'  => $province,
                        'is_active' => $isActive,
                        'notes'     => $notes,
                    ])
                );
                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row '{$name}': " . $e->getMessage();
            }
        }

        $msg = "Imported {$imported} area(s).";
        if ($errors) $msg .= ' Errors: ' . implode('; ', array_slice($errors, 0, 3));
        return redirect()->route('shipping.index')->with('success', $msg);
    }

    /**
     * API: get shipping areas for order form
     */
    public function apiList()
    {
        $areas = ShippingArea::where('is_active', true)->orderBy('name')->get()
            ->map(fn($a) => [
                'id'       => $a->id,
                'name'     => $a->name,
                'label'    => $a->name . ' — ' . ($a->isFlatFee()
                                ? 'Flat Rp ' . number_format($a->flat_fee, 0, ',', '.')
                                : 'Rp ' . number_format($a->price_per_kg, 0, ',', '.') . '/kg'),
                'flat_fee'             => $a->flat_fee,
                'flat_fee_subsidy_cap' => $a->flat_fee_subsidy_cap,
                'price_per_kg'         => $a->price_per_kg,
                'is_flat_fee'          => $a->isFlatFee(),
            ]);
        return response()->json($areas);
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $count = ShippingArea::count();
            // Null out all FK references first
            \App\Models\Order::whereNotNull('shipping_area_id')->update(['shipping_area_id' => null]);
            \App\Models\Customer::whereNotNull('default_shipping_area_id')->update(['default_shipping_area_id' => null]);
            ShippingArea::query()->delete();
            return redirect()->route('shipping.index')->with('success', "All {$count} shipping area(s) deleted.");
        }
        $ids = $request->input('ids', []);
        if (!empty($ids)) {
            ShippingArea::whereIn('id', $ids)->delete();
        }
        return redirect()->route('shipping.index')->with('success', count($ids) . ' shipping area(s) deleted.');
    }
}