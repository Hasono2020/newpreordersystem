<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\ShippingArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    use \App\Traits\HandlesXlsx;

    public function index(Request $request)
    {
        $query = Customer::withCount('orders');
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('phone', 'like', '%'.$request->search.'%');
            });
        }
        if ($request->type) {
            $query->where('type', $request->type);
        }
        $customers = $query->latest()->paginate(20)->withQueryString();
        return view('customers.index', compact('customers'));
    }

    public function create()
    {
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();
        return view('customers.create', compact('shippingAreas'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                     => 'required|string|max:255',
            'phone'                    => 'required|string|max:50|unique:customers,phone',
            'address'                  => 'nullable|string',
            'type'                     => 'required|in:customer,reseller,selected_customer',
            'notes'                    => 'nullable|string',
            'default_shipping_area_id' => 'required|exists:shipping_areas,id',
        ], [
            'phone.unique' => 'This phone number is already registered to another customer.',
        ]);

        $customer = Customer::create($data);
        if ($request->expectsJson()) return response()->json($customer);
        return redirect()->route('customers.show', $customer)->with('success', 'Customer added.');
    }

    public function show(Customer $customer)
    {
        $customer->load(['orders.trip', 'orders.items', 'defaultShippingArea']);
        return view('customers.show', compact('customer'));
    }

    public function edit(Customer $customer)
    {
        $shippingAreas = ShippingArea::where('is_active', true)->orderBy('name')->get();
        return view('customers.edit', compact('customer', 'shippingAreas'));
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name'                     => 'required|string|max:255',
            'phone'                    => 'required|string|max:50|unique:customers,phone,'.$customer->id,
            'address'                  => 'nullable|string',
            'type'                     => 'required|in:customer,reseller,selected_customer',
            'notes'                    => 'nullable|string',
            'default_shipping_area_id' => 'required|exists:shipping_areas,id',
        ], [
            'phone.unique' => 'This phone number is already registered to another customer.',
        ]);

        $oldAreaId = $customer->default_shipping_area_id;
        $newAreaId = $data['default_shipping_area_id'];

        $customer->update($data);

        // If shipping area changed, apply it to all this customer's orders
        // that currently have no shipping area set, then recalculate
        if ($newAreaId && $newAreaId != $oldAreaId) {
            $ordersToUpdate = $customer->orders()
                ->whereNull('shipping_area_id')
                ->with('items.product', 'items.variant', 'shippingArea')
                ->get();

            $promoSvc = app(\App\Services\PromoService::class);

            foreach ($ordersToUpdate as $order) {
                $order->update(['shipping_area_id' => $newAreaId]);
                $calc = $promoSvc->recalculate($order->fresh());
                $order->update([
                    'subtotal'             => $calc['subtotal'],
                    'discount_amount'      => $calc['discount_amount'],
                    'shipping_fee'         => $calc['shipping_fee'],
                    'shipping_discount'    => $calc['shipping_discount'],
                    'shipping_weight_gram' => $calc['shipping_weight_gram'],
                    'shipping_kg_charged'  => $calc['shipping_kg_charged'],
                    'total_amount'         => $calc['total_amount'],
                ]);
            }

            $count = $ordersToUpdate->count();
            $msg = 'Customer updated.';
            if ($count > 0) {
                $msg .= " Applied shipping area to {$count} order(s) that had none, and recalculated totals.";
            }
            return redirect()->route('customers.show', $customer)->with('success', $msg);
        }

        return redirect()->route('customers.show', $customer)->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer)
    {
        DB::transaction(function () use ($customer) {
            foreach ($customer->orders as $order) {
                $order->payments()->delete();
                $order->items()->delete();
                $order->delete();
            }
            $customer->delete();
        });
        return redirect()->route('customers.index')->with('success', 'Customer deleted.');
    }

    public function export()
    {
        $customers = Customer::with('defaultShippingArea')
            ->withCount('orders')
            ->withSum('orders as total_spent', 'total_amount')
            ->orderBy('name')->get();

        $rows = [['name','phone','type','shipping_area','address','notes','total_orders','total_spent']];
        foreach ($customers as $c) {
            $rows[] = [$c->name, $c->phone, $c->type,
                $c->defaultShippingArea?->name ?? '',
                $c->address, $c->notes,
                $c->orders_count, $c->total_spent ?? 0];
        }
        return $this->streamXlsx('customers_export.xlsx', $rows);
    }

    public function importCsv(Request $request)
    {
        $request->validate(['file' => 'required|file|max:5120']);

        $rows = $this->readXlsx($request->file('file')->getRealPath());
        if (empty($rows)) {
            return back()->with('error', 'Could not read the file. Make sure it is a valid .xlsx file.');
        }

        $header = array_shift($rows); // remove header row

        // ── Validation pass first ──────────────────────────────────────
        $validationErrors = [];
        foreach ($rows as $rowIdx => $row) {
            $lineNum  = $rowIdx + 2; // +2: 1=header, rowIdx starts at 0
            $name     = trim($row[0] ?? '');
            $phone    = trim($row[1] ?? '');
            $areaName = trim($row[3] ?? '');

            if (empty($name)) continue; // skip blank rows silently

            $issues = [];
            if (empty($phone))    $issues[] = 'Phone is required';
            if (empty($areaName)) $issues[] = 'Shipping Area is required';

            if (!empty($issues)) {
                $validationErrors[] = "Row {$lineNum} ({$name}): " . implode(', ', $issues) . '.';
            }
        }

        // If any validation errors found, stop and show them all
        if (!empty($validationErrors)) {
            $errorList = implode("\n", $validationErrors);
            return back()->with('import_errors', $validationErrors)
                         ->with('error', 'Import blocked — please fix the following issues in your Excel file before importing:');
        }

        // ── All rows valid — proceed with import ───────────────────────
        $imported = 0;
        $skipped  = 0;
        $skipReasons = [];

        foreach ($rows as $rowIdx => $row) {
            $lineNum  = $rowIdx + 2;
            $name     = trim($row[0] ?? '');
            $phone    = trim($row[1] ?? '');
            $type     = trim($row[2] ?? 'customer');
            $areaName = trim($row[3] ?? '');
            $address  = trim($row[4] ?? '');
            $notes    = trim($row[5] ?? '');

            if (empty($name)) continue;

            // Check duplicates
            if ($phone && Customer::where('phone', $phone)->exists()) {
                $skipped++;
                $skipReasons[] = "Row {$lineNum} ({$name}): phone {$phone} already exists.";
                continue;
            }
            if (!$phone && Customer::where('name', $name)->exists()) {
                $skipped++;
                $skipReasons[] = "Row {$lineNum} ({$name}): name already exists.";
                continue;
            }

            $shippingArea = $areaName
                ? \App\Models\ShippingArea::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($areaName).'%'])->first()
                : null;

            if ($areaName && !$shippingArea) {
                $skipped++;
                $skipReasons[] = "Row {$lineNum} ({$name}): shipping area '{$areaName}' not found in system.";
                continue;
            }

            Customer::create([
                'name'                     => $name,
                'phone'                    => $phone,
                'type'                     => in_array($type, ['customer','reseller','selected_customer']) ? $type : 'customer',
                'default_shipping_area_id' => $shippingArea?->id,
                'address'                  => $address,
                'notes'                    => $notes,
            ]);
            $imported++;
        }

        $msg = "Imported {$imported} customer(s) successfully.";
        if ($skipped) $msg .= " {$skipped} skipped: " . implode(' | ', $skipReasons);
        return redirect()->route('customers.index')->with('success', $msg);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'action'       => 'required|in:selected,no_orders',
            'customer_ids' => 'required_if:action,selected|array',
        ]);

        DB::transaction(function () use ($request) {
            $query = Customer::query();
            if ($request->action === 'selected') {
                $query->whereIn('id', $request->customer_ids);
            } elseif ($request->action === 'no_orders') {
                $query->doesntHave('orders');
            }
            foreach ($query->with('orders.payments', 'orders.items')->get() as $customer) {
                foreach ($customer->orders as $order) {
                    $order->payments()->delete();
                    $order->items()->delete();
                    $order->delete();
                }
                $customer->delete();
            }
        });

        return redirect()->route('customers.index')->with('success', 'Customers deleted.');
    }
}
