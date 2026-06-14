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
        $perPage = in_array((int)$request->per_page, [20, 50, 100, 200]) ? (int)$request->per_page : 20;
        $query   = Customer::withCount('orders');
        // Customers are shared — all roles see all customers
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('phone', 'like', '%'.$request->search.'%');
            });
        }
        if ($request->type) {
            $query->where('type', $request->type);
        }
        $customers = $query->latest()->paginate($perPage)->withQueryString();
        return view('customers.index', compact('customers', 'perPage'));
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

        $data['created_by'] = \Illuminate\Support\Facades\Auth::id();
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
        $this->adminOnly('delete customers');
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

    public function importTemplate()
    {
        return $this->streamXlsx('customer_import_template.xlsx', [
            ['Name', 'Phone', 'Type', 'Shipping Area', 'Address', 'Notes'],
            // Type: customer | reseller | selected_customer
            // Phone: Indonesian format (e.g. 081234567890)
            // Shipping Area: must match area name in the system
            ['JASMINE 7911', '081234567890', 'customer', 'SURABAYA', '', ''],
            ['SARI 0812',    '081298765432', 'reseller', 'JAKARTA',  '', 'VIP reseller'],
            ['TONO 5566',    '085612345678', 'customer', 'BANDUNG',  'Jl. Merdeka 10', ''],
        ]);
    }

    public function importCsv(Request $request)
    {
        $request->validate(['file' => 'required|file|max:10240']);

        $rows = $this->readXlsx($request->file('file')->getRealPath());
        if (empty($rows)) {
            return back()->with('error', 'Could not read the file. Make sure it is a valid .xlsx file.');
        }

        array_shift($rows); // remove header

        // ── Pre-load all existing data into memory (3 queries total) ──
        $existingPhones = DB::table('customers')
            ->whereNotNull('phone')->pluck('phone')
            ->mapWithKeys(fn($p) => [strtolower(trim($p)) => true])->toArray();

        $existingNames = DB::table('customers')
            ->pluck('name')
            ->mapWithKeys(fn($n) => [strtolower(trim($n)) => true])->toArray();

        $shippingAreas = \App\Models\ShippingArea::all()
            ->mapWithKeys(fn($a) => [strtolower(trim($a->name)) => $a->id])->toArray();

        // ── Validation pass ─────────────────────────────────────────────
        $validationErrors = [];
        foreach ($rows as $rowIdx => $row) {
            $lineNum  = $rowIdx + 2;
            $name     = trim($row[0] ?? '');
            $phone    = trim($row[1] ?? '');
            $areaName = trim($row[3] ?? '');
            if (empty($name)) continue;

            $issues = [];
            if (empty($phone))    $issues[] = 'Phone is required';
            if (empty($areaName)) $issues[] = 'Shipping Area is required';
            if (!empty($issues)) {
                $validationErrors[] = "Row {$lineNum} ({$name}): " . implode(', ', $issues) . '.';
            }
        }
        if (!empty($validationErrors)) {
            return back()->with('import_errors', $validationErrors)
                         ->with('error', 'Import blocked — fix the following issues:');
        }

        // ── Import pass ─────────────────────────────────────────────────
        $imported    = 0;
        $skipped     = 0;
        $skipReasons = [];
        $toInsert    = [];
        $now         = now()->toDateTimeString();

        foreach ($rows as $rowIdx => $row) {
            $lineNum  = $rowIdx + 2;
            $name     = trim($row[0] ?? '');
            $phone    = trim($row[1] ?? '');
            $type     = trim($row[2] ?? 'customer');
            $areaName = trim($row[3] ?? '');
            $address  = trim($row[4] ?? '');
            $notes    = trim($row[5] ?? '');

            if (empty($name)) continue;

            $normalizedPhone = \App\Models\Customer::normalizePhone($phone);

            // Dedup in memory
            if ($normalizedPhone && isset($existingPhones[strtolower($normalizedPhone)])) {
                $skipped++; $skipReasons[] = "Row {$lineNum} ({$name}): phone already exists."; continue;
            }
            if (!$normalizedPhone && isset($existingNames[strtolower($name)])) {
                $skipped++; $skipReasons[] = "Row {$lineNum} ({$name}): name already exists."; continue;
            }

            // Resolve shipping area in memory
            $areaId = null;
            if ($areaName) {
                $key = strtolower($areaName);
                $areaId = $shippingAreas[$key] ?? null;
                if (!$areaId) {
                    // fuzzy match
                    foreach ($shippingAreas as $aKey => $aId) {
                        if (str_contains($aKey, $key) || str_contains($key, $aKey)) {
                            $areaId = $aId; break;
                        }
                    }
                }
                if (!$areaId) {
                    $skipped++; $skipReasons[] = "Row {$lineNum} ({$name}): area '{$areaName}' not found."; continue;
                }
            }

            $toInsert[] = [
                'name'                     => $name,
                'phone'                    => $normalizedPhone ?: null,
                'type'                     => in_array($type, ['customer','reseller','selected_customer']) ? $type : 'customer',
                'default_shipping_area_id' => $areaId,
                'address'                  => $address ?: null,
                'notes'                    => $notes ?: null,
                'created_by'               => \Illuminate\Support\Facades\Auth::id(),
                'created_at'               => $now,
                'updated_at'               => $now,
            ];

            // Track in memory
            if ($normalizedPhone) $existingPhones[strtolower($normalizedPhone)] = true;
            $existingNames[strtolower($name)] = true;
            $imported++;

            // Batch insert every 100 rows
            if (count($toInsert) >= 100) {
                DB::table('customers')->insert($toInsert);
                $toInsert = [];
            }
        }

        if (!empty($toInsert)) {
            DB::table('customers')->insert($toInsert);
        }

        $msg = "✓ Imported {$imported} customer(s).";
        if ($skipped) $msg .= " {$skipped} skipped.";
        return redirect()->route('customers.index')->with($skipped ? 'warning' : 'success', $msg);
    }
    public function bulkDestroy(Request $request)
    {
        $this->adminOnly('bulk delete customers');
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