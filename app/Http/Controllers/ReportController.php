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
        $query = Order::with('customer', 'trip', 'shippingArea', 'items.product', 'items.variant');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $orders = $query->latest()->get();

        // One row per item — order info repeats on first item row, blank on subsequent rows
        $rows = [['order_number','customer_name','customer_type','customer_phone',
            'trip','shipping_area','code','product_name','color','size','qty',
            'unit_price','item_status',
            'subtotal','discount','shipping_fee','shipping_discount',
            'total_amount','paid','balance_due','payment_status','notes']];

        foreach ($orders as $o) {
            $base = [$o->order_number, $o->customer->name, $o->customer->type,
                $o->customer->phone, $o->trip->name, $o->shippingArea?->name ?? ''];

            $summaryBase = [$o->subtotal, $o->discount_amount, $o->shipping_fee,
                $o->shipping_discount, $o->total_amount, $o->deposit_paid,
                $o->total_amount - $o->deposit_paid, $o->payment_status, $o->notes ?? ''];

            if ($o->items->isEmpty()) {
                $rows[] = array_merge($base, ['','(no items)','','',0,'',''], $summaryBase);
            } else {
                foreach ($o->items as $i => $item) {
                    $itemCols = [
                        $item->product->product_code ?? '',
                        $item->product->name,
                        $item->variant?->color ?? '',
                        $item->variant?->size ?? '',
                        $item->quantity,
                        $item->unit_price,
                        $item->status,
                    ];
                    if ($i === 0) {
                        $rows[] = array_merge($base, $itemCols, $summaryBase);
                    } else {
                        $rows[] = array_merge(['','','','','',''], $itemCols, ['','','','','','','','','']);
                    }
                }
            }
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
            'product_name','product_code','color','size','unit_price','line_total','status','notes']];
        foreach ($items as $i) {
            $rows[] = [$i->order->order_number, $i->order->customer->name,
                $i->order->customer->type, $i->order->trip->name,
                $i->product->name, $i->product->product_code ?? '',
                $i->variant?->color ?? '', $i->variant?->size ?? '',
                $i->unit_price, $i->line_total, $i->status, $i->notes];
        }
        return $this->streamXlsx('order_items_export.xlsx', $rows);
    }

    public function orderImportTemplate()
    {
        return $this->streamXlsx('order_import_template.xlsx', [
            ['No', 'Name', 'Phone', 'Type', 'Area', 'Code', 'Color', 'Size', 'Qty', 'Price', 'DP', 'Date of DP', 'Notes'],
            // Type: customer | reseller | selected_customer (leave blank = customer)
            // Price: leave blank to use system product price.
            // DP can be on any row for a customer — each DP row creates a separate payment.
            [1, 'JASMINE 7911', '08123456789', 'customer',  'SURABAYA', 'NA_03', 'GREY',  'FZ', 2, '', 500000, '2026-05-03', ''],
            [2, 'JASMINE 7911', '',            '',          '',         'NA_03', 'BROWN', 'FZ', 1, '', '',      '',           ''],
            [3, 'SARI 0812',    '08129876543', 'reseller',  'JAKARTA',  'NZ_01', 'BLACK', 'M',  3, '', '',      '',           'fragile'],
            [4, 'SARI 0812',    '',            '',          '',         'NA_03', 'NAVY',  'FZ', 2, '', 300000,  '2026-05-04', ''],
        ]);
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
     * Import orders from Excel.
     *
     * COLUMNS (12 total — one row per item line):
     *  0  No          – row number (ignored)
     *  1  Name        – customer name (first row per customer; repeat or leave blank)
     *  2  Phone       – customer phone (first row per customer only)
     *  3  Area        – shipping area (first row per customer only)
     *  4  Code        – product code (required; must exist in selected trip)
     *  5  Color       – variant color
     *  6  Size        – variant size
     *  7  Qty         – quantity ordered (default 1)
     *  8  Price       – unit price (blank = use product price)
     *  9  DP          – deposit amount (first row per customer only)
     * 10  Date of DP  – deposit date YYYY-MM-DD or DD/MM/YYYY
     * 11  Notes       – order notes (first row per customer only)
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
            return back()->with('error', 'Could not read the file. Make sure it is a valid .xlsx file.');
        }

        array_shift($rows); // remove header

        // Remove completely blank rows
        $rows = array_values(array_filter($rows, fn($r) =>
            !empty(trim($r[1] ?? '')) || !empty(trim($r[4] ?? ''))
        ));

        // ── Validation pass ────────────────────────────────────────────
        $validationErrors = [];
        $currentName      = null;

        foreach ($rows as $rowIdx => $row) {
            $lineNum = $rowIdx + 2;
            $name    = trim($row[1] ?? '');
            $code    = strtoupper(trim($row[5] ?? ''));
            $color   = trim($row[6] ?? '');
            $size    = trim($row[7] ?? '');
            if ($name) $currentName = $name;

            // Every item row must have a product code
            if (empty($code)) {
                if ($currentName) {
                    $validationErrors[] = "Row {$lineNum} ({$currentName}): Product code is missing.";
                }
                continue;
            }

            // Must have a customer name (from this row or a previous row)
            if (empty($currentName)) {
                $validationErrors[] = "Row {$lineNum}: No customer name set — fill Name on the first row of each customer.";
                continue;
            }

            // Product must exist in this trip
            $product = Product::where('product_code', $code)
                ->where('trip_id', $trip->id)
                ->first();

            if (!$product) {
                $validationErrors[] = "Row {$lineNum} ({$currentName}): Product code '{$code}' not found in trip '{$trip->name}'.";
                continue;
            }

            // Variant must exist if color/size given
            if ($color || $size) {
                $query = $product->variants();
                if ($color) $query->whereRaw('LOWER(color) = ?', [strtolower($color)]);
                if ($size)  $query->whereRaw('LOWER(size) = ?',  [strtolower($size)]);
                if (!$query->exists()) {
                    $validationErrors[] = "Row {$lineNum} ({$currentName}): Variant '{$color}/{$size}' not found for product '{$code}'.";
                }
            }
        }

        if (!empty($validationErrors)) {
            return back()
                ->with('import_errors', $validationErrors)
                ->with('error', 'Import blocked — fix these issues in your Excel file before importing:');
        }

        // ── Import pass ────────────────────────────────────────────────
        $imported = 0;
        $promoSvc = app(\App\Services\PromoService::class);

        DB::transaction(function () use ($rows, $trip, &$imported, $promoSvc) {
            $currentOrder    = null;
            $currentCustName = null;

            foreach ($rows as $row) {
                $name   = trim($row[1] ?? '');
                $phone  = trim($row[2] ?? '');
                $type   = trim($row[3] ?? '');
                $area   = trim($row[4] ?? '');
                $code   = strtoupper(trim($row[5] ?? ''));
                $color  = trim($row[6] ?? '');
                $size   = trim($row[7] ?? '');
                $qty    = max(1, (int)($row[8] ?? 1));
                $price  = (float)($row[9] ?? 0);
                $dp     = (float)($row[10] ?? 0);
                $dpDate = trim($row[11] ?? '');
                $notes  = trim($row[12] ?? '');
                // Normalise type — default to 'customer' if blank or invalid
                $validTypes = ['customer', 'reseller', 'selected_customer'];
                $type = in_array(strtolower($type), $validTypes) ? strtolower($type) : 'customer';

                // Resolve current customer name (blank = continue previous)
                if ($name) $currentCustName = $name;
                if (!$currentCustName) continue;

                // Start a new order when name changes
                if ($name && $name !== ($currentOrder?->customer->name)) {

                    // Recalculate previous order
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

                    // Resolve shipping area
                    $shippingArea = $area
                        ? ShippingArea::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($area).'%'])->first()
                        : null;

                    // Find or create customer
                    $customer = null;
                    if ($phone) $customer = Customer::where('phone', $phone)->first();
                    if (!$customer) $customer = Customer::where('name', $name)->first();
                    if (!$customer) {
                        $customer = Customer::create([
                            'name'                     => $name,
                            'phone'                    => $phone ?: null,
                            'type'                     => $type,
                            'default_shipping_area_id' => $shippingArea?->id,
                        ]);
                    } else {
                        // Update type if explicitly provided (not blank)
                        $updates = [];
                        if (!empty(trim($row[3] ?? ''))) $updates['type'] = $type;
                        if ($shippingArea && !$customer->default_shipping_area_id) $updates['default_shipping_area_id'] = $shippingArea->id;
                        if (!empty($updates)) $customer->update($updates);
                    }

                    // Fallback: use customer's default area if none in file
                    if (!$shippingArea && $customer->default_shipping_area_id) {
                        $shippingArea = $customer->defaultShippingArea;
                    }

                    $currentOrder = Order::create([
                        'trip_id'          => $trip->id,
                        'customer_id'      => $customer->id,
                        'shipping_area_id' => $shippingArea?->id,
                        'notes'            => $notes ?: null,
                        'created_by'       => Auth::id(),
                    ]);
                    $imported++;
                }

                // Record DP payment if present on this row (works on any row for this customer)
                if ($currentOrder && $dp > 0) {
                    $parsedDate = now()->format('Y-m-d');
                    try {
                        if ($dpDate) {
                            $parsedDate = str_contains($dpDate, '/')
                                ? implode('-', array_reverse(explode('/', $dpDate)))
                                : \Carbon\Carbon::parse($dpDate)->format('Y-m-d');
                        }
                    } catch (\Exception $e) {}

                    $currentOrder->payments()->create([
                        'amount'      => $dp,
                        'type'        => 'deposit',
                        'method'      => 'transfer',
                        'paid_at'     => $parsedDate,
                        'recorded_by' => Auth::id(),
                    ]);

                    // Update deposit_paid total
                    $totalPaid = $currentOrder->payments()->sum('amount');
                    $payStatus = $totalPaid >= $currentOrder->total_amount && $currentOrder->total_amount > 0
                        ? 'paid' : 'partial';
                    $currentOrder->update(['deposit_paid' => $totalPaid, 'payment_status' => $payStatus]);
                }

                // Add item to current order
                if ($currentOrder && $code) {
                    $product = Product::where('product_code', $code)
                        ->where('trip_id', $trip->id)
                        ->first();

                    if ($product) {
                        $variant = null;
                        if ($color || $size) {
                            $query = $product->variants();
                            if ($color) $query->whereRaw('LOWER(color) = ?', [strtolower($color)]);
                            if ($size)  $query->whereRaw('LOWER(size) = ?',  [strtolower($size)]);
                            $variant = $query->first();
                        }

                        // Use system price (blank price = use product/variant price)
                        $unitPrice = ($price > 0) ? $price : ($variant?->final_price ?? $product->price);
                        $currentOrder->items()->create([
                            'product_id'         => $product->id,
                            'product_variant_id' => $variant?->id,
                            'quantity'           => $qty,
                            'unit_price'         => $unitPrice,
                            'line_total'         => $unitPrice * $qty,
                            'status'             => 'pending',
                        ]);
                    }
                }
            }

            // Recalculate final order
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

        return redirect()
            ->route('orders.index', ['trip_id' => $trip->id])
            ->with('success', "✓ Imported {$imported} order(s) for trip '{$trip->name}'. Check below.");
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
