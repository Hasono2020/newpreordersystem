<?php

namespace App\Http\Controllers;

use App\Models\ShippingArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class ShippingAreaController extends Controller
{
    public function index(Request $request)
    {
        $query = ShippingArea::query();
        if ($request->search) {
            $query->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('province', 'like', '%'.$request->search.'%');
        }
        $areas = $query->orderBy('name')->paginate(30)->withQueryString();
        return view('shipping.index', compact('areas'));
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
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="shipping_areas_template.csv"',
        ];
        $callback = function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'province', 'price_per_kg', 'is_active', 'notes']);
            fputcsv($out, ['Batam', 'Kepulauan Riau', '25000', '1', '']);
            fputcsv($out, ['Jakarta Pusat', 'DKI Jakarta', '30000', '1', '']);
            fclose($out);
        };
        return Response::stream($callback, 200, $headers);
    }

    /**
     * Export all shipping areas as CSV
     */
    public function export()
    {
        $areas = ShippingArea::orderBy('name')->get();
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="shipping_areas_export.csv"',
        ];
        $callback = function () use ($areas) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'province', 'price_per_kg', 'is_active', 'notes']);
            foreach ($areas as $area) {
                fputcsv($out, [
                    $area->name,
                    $area->province,
                    $area->price_per_kg,
                    $area->is_active ? '1' : '0',
                    $area->notes,
                ]);
            }
            fclose($out);
        };
        return Response::stream($callback, 200, $headers);
    }

    /**
     * Import from CSV file
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file    = $request->file('file');
        $handle  = fopen($file->getRealPath(), 'r');
        $header  = fgetcsv($handle); // skip header row

        $imported = 0;
        $errors   = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) continue;
            [$name, $province, $price_per_kg, $is_active, $notes] = array_pad($row, 5, null);

            if (empty(trim($name ?? ''))) continue;

            try {
                ShippingArea::updateOrCreate(
                    ['name' => trim($name)],
                    [
                        'province'     => trim($province ?? ''),
                        'price_per_kg' => (float) str_replace(',', '', $price_per_kg ?? 0),
                        'is_active'    => in_array(trim($is_active ?? '1'), ['1', 'true', 'yes', 'active']),
                        'notes'        => trim($notes ?? ''),
                    ]
                );
                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row '{$name}': " . $e->getMessage();
            }
        }

        fclose($handle);

        $msg = "Imported {$imported} areas.";
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
