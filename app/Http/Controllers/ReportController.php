<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;
use App\Models\Payment;
use App\Services\PromoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class ReportController extends Controller
{
    use \App\Traits\HandlesXlsx;
    public function index(Request $request)
    {
        $trips   = Trip::orderByDesc('id')->get();
        $tripId  = $request->trip_id;

        $query = Order::query();
        if ($tripId) $query->where('trip_id', $tripId);

        $summary = [
            'total_orders'   => $query->count(),
            'total_revenue'  => (clone $query)->sum('total_amount'),
            'total_paid'     => (clone $query)->sum('deposit_paid'),
            'total_unpaid'   => (clone $query)->sum(DB::raw('total_amount - deposit_paid')),
            'paid_orders'    => (clone $query)->where('payment_status', 'paid')->count(),
            'partial_orders' => (clone $query)->where('payment_status', 'partial')->count(),
            'unpaid_orders'  => (clone $query)->where('payment_status', 'unpaid')->count(),
        ];

        $topCustomers = Customer::withSum(['orders as total_spent' => function ($q) use ($tripId) {
            if ($tripId) $q->where('trip_id', $tripId);
        }], 'total_amount')
        ->withCount(['orders as order_count' => function ($q) use ($tripId) {
            if ($tripId) $q->where('trip_id', $tripId);
        }])
        ->orderByDesc('total_spent')->limit(10)->get();

        $topProducts = Product::withSum(['orderItems as total_qty' => function ($q) use ($tripId) {
            $q->whereNotIn('status', ['cancelled', 'sold_out']);
            if ($tripId) $q->whereHas('order', fn($o) => $o->where('trip_id', $tripId));
        }], 'quantity')
        ->withSum(['orderItems as total_revenue' => function ($q) use ($tripId) {
            $q->whereNotIn('status', ['cancelled', 'sold_out']);
            if ($tripId) $q->whereHas('order', fn($o) => $o->where('trip_id', $tripId));
        }], 'line_total')
        ->orderByDesc('total_qty')->limit(10)->get();

        $salesByTrip = Trip::withSum('orders as total_revenue', 'total_amount')
            ->withSum('orders as total_paid', 'deposit_paid')
            ->withCount('orders')->orderByDesc('id')->get();

        $selectedTrip = $tripId ? Trip::find($tripId) : null;

        return view('reports.index', compact(
            'trips', 'summary', 'topCustomers', 'topProducts', 'salesByTrip', 'selectedTrip'
        ));
    }

    // ── CSV Exports ──────────────────────────────────────────────────

    public function exportOrders(Request $request)
    {
        $query = Order::with('customer', 'trip', 'shippingArea', 'createdBy');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $orders = $query->latest()->get();

        $rows = [['order_number','customer_name','customer_type','customer_phone',
            'trip','shipping_area','subtotal','discount','shipping_fee',
            'shipping_discount','total_amount','paid','balance_due','payment_status','created_at','notes']];
        foreach ($orders as $o) {
            $rows[] = [$o->order_number, $o->customer->name, $o->customer->type,
                $o->customer->phone, $o->trip->name, $o->shippingArea?->name ?? '',
                $o->subtotal, $o->discount_amount, $o->shipping_fee, $o->shipping_discount,
                $o->total_amount, $o->deposit_paid, $o->total_amount - $o->deposit_paid,
                $o->payment_status, $o->created_at->format('Y-m-d H:i'), $o->notes];
        }
        return $this->streamXlsx('orders_export.xlsx', $rows);
    }

    public function exportOrderItems(Request $request)
    {
        $query = OrderItem::with('order.customer', 'order.trip', 'product', 'variant');
        if ($request->trip_id) {
            $query->whereHas('order', fn($q) => $q->where('trip_id', $request->trip_id));
        }
        $items = $query->latest()->get();

        $rows = [['order_number','customer_name','customer_type','trip',
            'product_name','product_code','color','size','quantity','unit_price','line_total','status','notes']];
        foreach ($items as $i) {
            $rows[] = [$i->order->order_number, $i->order->customer->name,
                $i->order->customer->type, $i->order->trip->name,
                $i->product->name, $i->product->product_code ?? '',
                $i->variant?->color ?? '', $i->variant?->size ?? '',
                $i->quantity, $i->unit_price, $i->line_total, $i->status, $i->notes];
        }
        return $this->streamXlsx('order_items_export.xlsx', $rows);
    }

    public function exportCustomers()
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

    public function exportProducts(Request $request)
    {
        $query = Product::with('trip', 'supplier')->withSum(['orderItems as total_ordered' =>
            fn($q) => $q->whereNotIn('status', ['cancelled','sold_out'])], 'quantity');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $products = $query->orderBy('name')->get();

        $rows = [['trip','name','product_code','sku','brand','supplier','price','weight_gram','excluded_from_promo','status','total_ordered']];
        foreach ($products as $p) {
            $rows[] = [$p->trip->name, $p->name,
                $p->product_code ?? '', $p->sku ?? '', $p->brand ?? '',
                $p->supplier?->name ?? '',
                $p->price, $p->weight_gram,
                $p->excluded_from_promo ? 'yes' : 'no',
                $p->status, $p->total_ordered ?? 0];
        }
        return $this->streamXlsx('products_export.xlsx', $rows);
    }

    // ── Excel Order Import ───────────────────────────────────────────

    /**
     * Template columns:
     * No | Name | Phone | Area | Code | Color | Size | Qty | Price | DP | Date of DP | Notes
     */
    public function orderImportTemplate()
    {
        $xml = $this->buildXlsx([
            ['No', 'Name', 'Phone', 'Area', 'Code', 'Color', 'Size', 'Qty', 'Price', 'DP', 'Date of DP', 'Notes'],
            // Example: Jasmine orders 2 GREY FZ and 1 BROWN FZ, paid DP 500,000 on 3 May
            [1, 'JASMINE 7911', '08123456789', 'SURABAYA', 'NA_03', 'GREY', 'FZ', 2, 169000, 500000, '2026-05-03', ''],
            [2, 'JASMINE 7911', '', '', 'NA_03', 'BROWN', 'FZ', 1, 169000, '', '', ''],
            // Example: different customer, area only needs to be on first row
            [3, 'SARI 0812', '08129876543', 'JAKARTA', 'NZ_01', 'BLACK', 'M', 3, 250000, '', '', 'fragile'],
            [4, 'SARI 0812', '', '', 'NA_03', 'NAVY', 'FZ', 2, 169000, 300000, '2026-05-04', ''],
        ]);

        return Response::make($xml, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="order_import_template.xlsx"',
        ]);
    }

    /**
     * Import orders from Excel.
     *
     * COLUMNS (12 total):
     *  0  No          – row number (ignored, just for readability)
     *  1  Name        – customer name (required on first item row; repeat or leave blank for same customer)
     *  2  Phone       – customer phone (only needed on first row per customer)
     *  3  Area        – shipping area name (only needed on first row per customer)
     *  4  Code        – product code (required; must exist in the selected trip)
     *  5  Color       – variant color (leave blank if product has no variants)
     *  6  Size        – variant size  (leave blank if product has no variants)
     *  7  Qty         – quantity ordered (required; defaults to 1 if blank)
     *  8  Price       – unit price override (leave blank to use product's price)
     *  9  DP          – deposit paid amount (only needed on first row per customer)
     * 10  Date of DP  – deposit date in YYYY-MM-DD or DD/MM/YYYY (optional)
     * 11  Notes       – order notes (optional)
     *
     * GROUPING RULES:
     *  - Rows are grouped into one order per customer.
     *  - A new order starts when the Name column changes (or first row with a name).
     *  - Subsequent rows with blank Name are added to the most recent customer's order.
     *  - Phone, Area, DP are only read from the FIRST row of each customer.
     */
    public function importOrders(Request $request)
    {
        $request->validate([
            'file'    => 'required|file|max:10240',
            'trip_id' => 'required|exists:trips,id',
        ]);

        $trip = Trip::findOrFail($request->trip_id);
        $rows = $this->readXlsx($request->file('file')->getRealPath());

        if (empty($rows)) {
            return back()->with('error', 'Could not read the file. Make sure it is a valid .xlsx file with data.');
        }

        // Remove header row
        array_shift($rows);

        $imported  = 0;
        $skipped   = 0;
        $warnings  = [];
        $promoSvc  = app(\App\Services\PromoService::class);

        DB::transaction(function () use ($rows, $trip, &$imported, &$skipped, &$warnings, $promoSvc) {
            $currentOrder    = null;
            $currentCustName = null;

            foreach ($rows as $rowIdx => $row) {
                $lineNum = $rowIdx + 2; // +2 because header was row 1

                // --- Parse columns ---
                $name    = trim($row[1] ?? '');
                $phone   = trim($row[2] ?? '');
                $area    = trim($row[3] ?? '');
                $code    = strtoupper(trim($row[4] ?? ''));
                $color   = trim($row[5] ?? '');
                $size    = trim($row[6] ?? '');
                $qty     = max(1, (int)($row[7] ?? 1));
                $price   = (float)($row[8] ?? 0);
                $dp      = (float)($row[9] ?? 0);
                $dpDate  = trim($row[10] ?? '');
                $notes   = trim($row[11] ?? '');

                // Skip completely empty rows
                if (empty($name) && empty($code)) continue;

                // --- Start new order when name changes ---
                if ($name && $name !== $currentCustName) {

                    // Recalculate previous order before starting a new one
                    if ($currentOrder) {
                        $calc = $promoSvc->recalculate($currentOrder->fresh());
                        $currentOrder->update([
                            'subtotal'             => $calc['subtotal'],
                            'discount_amount'      => $calc['discount_amount'],
                            'shipping_fee'         => $calc['shipping_fee'],
                            'shipping_discount'    => $calc['shipping_discount'],
                            'shipping_weight_gram' => $calc['shipping_weight_gram'],
                            'shipping_kg_charged'  => $calc['shipping_kg_charged'],
                            'total_amount'         => $calc['total_amount'],
                        ]);
                    }

                    // Resolve shipping area (match by name, case-insensitive)
                    $shippingArea = null;
                    if ($area) {
                        $shippingArea = ShippingArea::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($area).'%'])->first();
                        if (!$shippingArea) {
                            $warnings[] = "Row {$lineNum}: Shipping area '{$area}' not found — order created without shipping area.";
                        }
                    }

                    // Find existing customer by phone first, then name
                    $customer = null;
                    if ($phone) {
                        $customer = Customer::where('phone', $phone)->first();
                    }
                    if (!$customer && $name) {
                        $customer = Customer::where('name', $name)->first();
                    }

                    // Create if not found
                    if (!$customer) {
                        $customer = Customer::create([
                            'name'                     => $name,
                            'phone'                    => $phone ?: 'imported-'.$lineNum,
                            'type'                     => 'customer',
                            'default_shipping_area_id' => $shippingArea?->id,
                        ]);
                    } else {
                        // Update missing fields
                        $updates = [];
                        if ($phone && !$customer->phone) $updates['phone'] = $phone;
                        if ($shippingArea && !$customer->default_shipping_area_id) $updates['default_shipping_area_id'] = $shippingArea->id;
                        if (!empty($updates)) $customer->update($updates);
                    }

                    // Create the order
                    $currentOrder = Order::create([
                        'trip_id'          => $trip->id,
                        'customer_id'      => $customer->id,
                        'shipping_area_id' => $shippingArea?->id,
                        'notes'            => $notes ?: null,
                        'created_by'       => Auth::id(),
                    ]);

                    $currentCustName = $name;
                    $imported++;

                    // Record deposit payment if provided
                    if ($dp > 0) {
                        $parsedDate = now()->format('Y-m-d');
                        try {
                            if ($dpDate) {
                                // Support both YYYY-MM-DD and DD/MM/YYYY
                                if (str_contains($dpDate, '/')) {
                                    $parts = explode('/', $dpDate);
                                    $parsedDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                                } else {
                                    $parsedDate = \Carbon\Carbon::parse($dpDate)->format('Y-m-d');
                                }
                            }
                        } catch (\Exception $e) {}

                        $currentOrder->payments()->create([
                            'amount'      => $dp,
                            'type'        => 'deposit',
                            'method'      => 'transfer',
                            'paid_at'     => $parsedDate,
                            'recorded_by' => Auth::id(),
                        ]);
                        $currentOrder->update(['deposit_paid' => $dp, 'payment_status' => 'partial']);
                    }
                }

                // --- Add order item ---
                if ($currentOrder && $code) {
                    $product = Product::where('product_code', $code)
                        ->where('trip_id', $trip->id)
                        ->first();

                    if (!$product) {
                        $warnings[] = "Row {$lineNum}: Product code '{$code}' not found in trip '{$trip->name}' — skipped.";
                        $skipped++;
                        continue;
                    }

                    // Find variant by color+size
                    $variant = null;
                    if ($color || $size) {
                        $query = $product->variants();
                        if ($color) $query->whereRaw('LOWER(color) = ?', [strtolower($color)]);
                        if ($size)  $query->whereRaw('LOWER(size) = ?',  [strtolower($size)]);
                        $variant = $query->first();

                        if (!$variant && ($color || $size)) {
                            $warnings[] = "Row {$lineNum}: Variant '{$color}/{$size}' not found for '{$code}' — item added without variant.";
                        }
                    }

                    $unitPrice = $price ?: ($variant ? $variant->final_price : $product->price);

                    $currentOrder->items()->create([
                        'product_id'         => $product->id,
                        'product_variant_id' => $variant?->id,
                        'quantity'           => $qty,
                        'unit_price'         => $unitPrice,
                        'line_total'         => $unitPrice * $qty,
                        'status'             => 'pending',
                    ]);
                } elseif ($currentOrder === null && $code) {
                    $warnings[] = "Row {$lineNum}: Item '{$code}' has no customer — skipped. Make sure Name is filled on the first row of each customer.";
                    $skipped++;
                }
            }

            // Recalculate the last order in the file
            if ($currentOrder) {
                $calc = $promoSvc->recalculate($currentOrder->fresh());
                $currentOrder->update([
                    'subtotal'             => $calc['subtotal'],
                    'discount_amount'      => $calc['discount_amount'],
                    'shipping_fee'         => $calc['shipping_fee'],
                    'shipping_discount'    => $calc['shipping_discount'],
                    'shipping_weight_gram' => $calc['shipping_weight_gram'],
                    'shipping_kg_charged'  => $calc['shipping_kg_charged'],
                    'total_amount'         => $calc['total_amount'],
                ]);
            }
        });

        $msg = "✓ Imported {$imported} order(s).";
        if ($skipped)          $msg .= " {$skipped} item(s) skipped.";
        if (!empty($warnings)) $msg .= " Warnings: ".implode(' | ', array_slice($warnings, 0, 5));

        $flash = empty($warnings) ? 'success' : 'warning';
        return redirect()->route('reports.index')->with($flash, $msg);
    }

    public function importCustomers(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);
        $handle   = fopen($request->file('file')->getRealPath(), 'r');
        $header   = fgetcsv($handle); // skip header
        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $name  = trim($row[0] ?? '');
            $phone = trim($row[1] ?? '');
            $type  = trim($row[2] ?? 'customer');
            $areaName = trim($row[3] ?? '');
            $address  = trim($row[4] ?? '');
            $notes    = trim($row[5] ?? '');

            if (empty($name)) continue;

            // Find shipping area by name
            $shippingArea = $areaName
                ? ShippingArea::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($areaName).'%'])->first()
                : null;

            if (!Customer::where('name', $name)->exists()) {
                Customer::create([
                    'name'                     => $name,
                    'phone'                    => $phone ?: 'imported',
                    'type'                     => in_array($type, ['customer','reseller','selected_customer']) ? $type : 'customer',
                    'default_shipping_area_id' => $shippingArea?->id,
                    'address'                  => $address,
                    'notes'                    => $notes,
                ]);
                $imported++;
            }
        }
        fclose($handle);
        return redirect()->route('reports.index')->with('success', "Imported {$imported} customers.");
    }

}
