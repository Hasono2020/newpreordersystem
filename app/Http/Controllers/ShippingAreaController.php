<?php

namespace App\Http\Controllers;

use App\Models\ShippingArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ShippingAreaController extends Controller
{
    use \App\Traits\HandlesXlsx;
    public function index(Request $request)
    {
        $perPage = in_array((int)$request->per_page, [30, 50, 100, 200]) ? (int)$request->per_page : 50;
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
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'province'     => 'nullable|string|max:255',
            'price_per_kg' => 'required|numeric|min:0',
            'is_active'    => 'boolean',
            'notes'        => 'nullable|string',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        ShippingArea::create($data);
        return redirect()->route('shipping.index')->with('success', 'Shipping area added.');
    }

    public function edit(ShippingArea $shipping)
    {
        return view('shipping.edit', compact('shipping'));
    }

    public function update(Request $request, ShippingArea $shipping)
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'province'     => 'nullable|string|max:255',
            'price_per_kg' => 'required|numeric|min:0',
            'is_active'    => 'boolean',
            'notes'        => 'nullable|string',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $shipping->update($data);
        return redirect()->route('shipping.index')->with('success', 'Shipping area updated.');
    }

    public function destroy(ShippingArea $shipping)
    {
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
            ['name', 'province', 'price_per_kg', 'is_active', 'notes'],
            ['Batam', 'Kepulauan Riau', 25000, 1, ''],
            ['Jakarta Pusat', 'DKI Jakarta', 30000, 1, ''],
            ['Surabaya', 'Jawa Timur', 35000, 1, ''],
        ]);
    }

    public function export()
    {
        $areas = ShippingArea::orderBy('name')->get();
        $rows  = [['name', 'province', 'price_per_kg', 'is_active', 'notes']];
        foreach ($areas as $area) {
            $rows[] = [$area->name, $area->province, $area->price_per_kg, $area->is_active ? 1 : 0, $area->notes];
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
            $name      = trim($row[0] ?? '');
            $province  = trim($row[1] ?? '');
            $price     = (float) str_replace(',', '', $row[2] ?? 0);
            $isActive  = in_array(strtolower((string)($row[3] ?? '1')), ['1','true','yes','active']);
            $notes     = trim($row[4] ?? '');

            if (empty($name)) continue;

            try {
                ShippingArea::updateOrCreate(
                    ['name' => $name],
                    ['province' => $province, 'price_per_kg' => $price, 'is_active' => $isActive, 'notes' => $notes]
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
        return response()->json(ShippingArea::where('is_active', true)->orderBy('name')->get());
    }
}
