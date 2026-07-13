<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use \App\Traits\HandlesXlsx;
    public function index(Request $request)
    {
        $trips   = Trip::orderByDesc('id')->get();
        $tripId  = $request->trip_id;

        $query = Order::query();
        if (\Illuminate\Support\Facades\Auth::user()->isOwnDataOnly()) {
            $query->where('created_by', \Illuminate\Support\Facades\Auth::id());
        }
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

    /**
     * Export orders — one row per item, matching the LIST ORDERAN CUSTOMER format.
     * Columns: KET | NO | NAMA | IG/WA | KOTA | KODE | WARNA | SIZE | HARGA SATUAN | DP | TGL DP | AN | KET
     */
    public function exportOrders(Request $request)
    {
        $query = Order::with('customer', 'trip', 'shippingArea', 'items.product', 'items.variant', 'payments', 'csAgent', 'createdBy')
            ->orderBy('ordered_at');
        if (\Illuminate\Support\Facades\Auth::user()->isOwnDataOnly()) {
            $query->where('created_by', \Illuminate\Support\Facades\Auth::id());
        }
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $orders = $query->get();

        $rows = [[
            'DIBUAT OLEH', 'NO', 'NAMA', 'IG/WA', 'NO HP', 'KOTA',
            'KODE', 'WARNA', 'SIZE', 'HARGA SATUAN',
            'DP', 'TGL DP', 'AN', 'KET', 'WAKTU ORDER',
        ]];

        $no = 1;
        foreach ($orders as $o) {
            $firstPayment = $o->payments->sortBy('paid_at')->first();
            $dp    = $firstPayment?->amount ?? ($o->deposit_paid ?: '');
            $tglDp = $firstPayment ? \Carbon\Carbon::parse($firstPayment->paid_at)->format('d-M-y') : '';
            $an    = $o->notes ?? '';
            $waktuOrder = $o->created_at?->format('d-m-Y H:i') ?? '';

            // Cancelled/sold-out items didn't ship and shouldn't appear in
            // the export at all — matches the same treatment they already
            // get on the printed invoices (Rp 0 there; skipped entirely here).
            $activeItems = $o->items->whereNotIn('status', ['cancelled', 'sold_out']);

            foreach ($activeItems as $item) {
                // Repeat the row once per unit ordered, matching the import
                // template's convention (each row = 1 unit; multiple units of
                // the same product/variant = repeated identical rows).
                $qty = max(1, (int) ($item->quantity ?? 1));

                for ($u = 0; $u < $qty; $u++) {
                    $rows[] = [
                        $o->createdBy?->name ?? '',          // DIBUAT OLEH (created by)
                        $no,
                        $o->customer->name,                  // NAMA
                        $o->csAgent?->name ?? '',            // IG/WA (CS who handled livechat)
                        $o->customer->phone ?? '',           // NO HP (customer phone)
                        $o->shippingArea?->name ?? '',       // KOTA
                        $item->product->product_code ?? '',  // KODE
                        $item->variant?->color ?? '',        // WARNA
                        $item->variant?->size ?? '',         // SIZE
                        $item->unit_price,                   // HARGA SATUAN
                        $dp,                                 // DP
                        $tglDp,                               // TGL DP
                        $an,                                 // AN
                        '',                                  // KET
                        $waktuOrder,                         // WAKTU ORDER (order created_at)
                    ];
                    $no++;
                    // Only show DP / Waktu Order on the very first row of the order
                    $dp    = '';
                    $tglDp = '';
                    $waktuOrder = '';
                }
            }

            // If order has no exportable items (none at all, or every item
            // was cancelled/sold out), still emit one row for the order
            // itself so the customer/order isn't silently dropped.
            if ($activeItems->isEmpty()) {
                $rows[] = [
                    $o->createdBy?->name ?? '', $no, $o->customer->name,
                    $o->csAgent?->name ?? '',
                    $o->customer->phone ?? '',
                    $o->shippingArea?->name ?? '',
                    '', '', '', '',
                    $dp, $tglDp, $an, '',
                    $waktuOrder,
                ];
                $no++;
            }
        }

        return $this->streamXlsx('orders_export.xlsx', $rows);
    }

    public function exportOrderItems(Request $request)
    {
        // Alias to exportOrders for compatibility
        return $this->exportOrders($request);
    }

    /**
     * Import template — matches LIST ORDERAN CUSTOMER format.
     * Each row = 1 separate order with 1 item.
     *
     * COLUMNS (13):
     *  0  KET         – notes/remarks (ignored on import)
     *  1  NO          – row number (determines FIFO order when Ordered At blank)
     *  2  NAMA        – customer name
     *  3  IG/WA       – customer phone or IG/WA contact
     *  4  KOTA        – shipping area name
     *  5  KODE        – product code (must exist in selected trip)
     *  6  WARNA       – variant color
     *  7  SIZE        – variant size
     *  8  HARGA SATUAN– unit price (blank = use system product price)
     *  9  DP          – deposit amount
     * 10  TGL DP      – deposit date (DD/MM/YYYY or YYYY-MM-DD)
     * 11  AN          – atas nama / order notes
     * 12  KET         – additional notes
     */
    public function orderImportTemplate()
    {
        return $this->streamXlsx('order_import_template.xlsx', [
            ['DIBUAT OLEH', 'NO', 'NAMA', 'IG/WA', 'NO HP', 'KOTA', 'KODE', 'WARNA', 'SIZE', 'HARGA SATUAN', 'DP', 'TGL DP', 'AN', 'KET'],
            // Each row = 1 order item. Row order = FIFO priority (top = first).
            // IG/WA = CS who handled the livechat (must match a CS agent name).
            // NO HP = customer phone. Leave HARGA SATUAN blank to use system product price.
            ['', 1, 'JASMINE 7911', 'Rina', '08123456789', 'SURABAYA', 'NA_03', 'GREY',  'FZ', '', 500000, '2026-05-03', 'JASMINE', ''],
            ['', 2, 'JASMINE 7911', 'Rina', '',            '',         'NA_03', 'BROWN', 'FZ', '', '',     '',           '',        ''],
            ['', 3, 'JASMINE 7911', 'Rina', '',            '',         'NA_03', 'GREY',  'FZ', '', '',     '',           '',        ''],
            ['', 4, 'SARI 0812',    'Dewi', '08129876543', 'JAKARTA',  'NZ_01', 'BLACK', 'M',  '', 300000, '2026-05-04', 'SARI',    'fragile'],
            ['', 5, 'SARI 0812',    'Dewi', '',            '',         'NA_03', 'NAVY',  'FZ', '', '',     '',           '',        ''],
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
        $products = $query->orderBy('product_code')->get();

        $rows = [['trip','product_code','sku','brand','supplier','price','weight_gram','excluded_from_promo','status','total_ordered']];
        foreach ($products as $p) {
            $rows[] = [$p->trip->name,
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
     * COLUMNS (14 total — one row per item line):
     *  0  KET          – status/remark (ignored on import)
     *  1  NO           – row number (FIFO order; ignored otherwise)
     *  2  NAMA         – customer name
     *  3  IG/WA        – CS agent who handled the livechat (matched by name)
     *  4  NO HP        – customer phone
     *  5  KOTA         – shipping area name
     *  6  KODE         – product code (required; must exist in selected trip)
     *  7  WARNA        – variant color
     *  8  SIZE         – variant size
     *  9  HARGA SATUAN – unit price (blank = use product price)
     * 10  DP           – deposit amount
     * 11  TGL DP       – deposit date YYYY-MM-DD or DD/MM/YYYY
     * 12  AN           – atas nama / order notes
     * 13  KET          – additional notes
     *
     * This method is now lightweight: it just validates the upload itself,
     * stores the file, and queues an ImportOrdersJob. The actual row
     * validation + database writes happen later in the queued job — see
     * App\Jobs\ImportOrdersJob and App\Services\OrderImportService.
     */
    public function importOrders(Request $request)
    {
        $request->validate([
            'file'    => 'required|file|max:51200',
            'trip_id' => 'required|exists:trips,id',
        ]);

        $trip = Trip::findOrFail($request->trip_id);

        // Store the upload persistently (the temp upload file is removed once
        // the request ends, but the queue worker runs later in a separate process).
        $storedPath = $request->file('file')->store('imports');

        $importJob = \App\Models\ImportJob::create([
            'trip_id'           => $trip->id,
            'created_by'        => Auth::id(),
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'stored_path'       => $storedPath,
            'status'            => 'queued',
        ]);

        \App\Jobs\ImportOrdersJob::dispatch($importJob->id);

        return redirect()
            ->route('orders.index', ['trip_id' => $trip->id])
            ->with('success', "Import queued for '{$trip->name}' — processing in the background. Check the Recent Imports panel for progress.");
    }

    /**
     * AJAX: poll status of the current user's recent import jobs (for the
     * "Recent Imports" panel on the orders index page).
     */
    public function importStatus(Request $request)
    {
        $jobs = \App\Models\ImportJob::where('created_by', Auth::id())
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'trip_id', 'original_filename', 'status', 'total_rows', 'imported_count', 'skipped_count', 'error_message', 'row_errors', 'created_at', 'finished_at']);

        return response()->json($jobs);
    }

    public function importCustomers(Request $request)
    {
        set_time_limit(300); // allow up to 5 minutes for large files
        @ini_set('memory_limit', '1024M');
        DB::connection()->disableQueryLog();

        $request->validate(['file' => 'required|file|max:10240']);

        $rows = $this->readXlsx($request->file('file')->getRealPath());
        if (empty($rows)) {
            return back()->with('error', 'Could not read the file. Make sure it is a valid .xlsx file.');
        }

        array_shift($rows); // remove header

        // ── Pre-load existing data into memory (avoid per-row DB queries) ──
        $existingPhones = Customer::whereNotNull('phone')
            ->pluck('phone')
            ->map(fn($p) => strtolower(trim($p)))
            ->flip()
            ->toArray(); // [normalized_phone => true]

        $existingNames = Customer::pluck('name')
            ->map(fn($n) => strtolower(trim($n)))
            ->flip()
            ->toArray(); // [name => true]

        // Pre-load shipping areas keyed by lowercase name
        $shippingAreas = ShippingArea::all()->keyBy(fn($a) => strtolower(trim($a->name)));
        $areaIdCache   = []; // [search_term => area_id]

        $imported    = 0;
        $skipped     = 0;
        $toInsert    = [];
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

            $normalizedPhone = Customer::normalizePhone($phone);

            // Check duplicates in memory
            if ($normalizedPhone && isset($existingPhones[strtolower($normalizedPhone)])) {
                $skipped++;
                $skipReasons[] = "Row {$lineNum} ({$name}): phone already exists.";
                continue;
            }
            if (!$normalizedPhone && isset($existingNames[strtolower($name)])) {
                $skipped++;
                $skipReasons[] = "Row {$lineNum} ({$name}): name already exists.";
                continue;
            }

            // Resolve shipping area in memory
            $areaId = null;
            if ($areaName) {
                $cacheKey = strtolower($areaName);
                if (!array_key_exists($cacheKey, $areaIdCache)) {
                    // Find best match by substring
                    $match = null;
                    foreach ($shippingAreas as $key => $area) {
                        if (str_contains($key, $cacheKey) || str_contains($cacheKey, $key)) {
                            $match = $area;
                            break;
                        }
                    }
                    $areaIdCache[$cacheKey] = $match?->id;
                }
                $areaId = $areaIdCache[$cacheKey];
            }

            $validType = in_array($type, ['customer','reseller','selected_customer']) ? $type : 'customer';
            $now       = now()->toDateTimeString();

            $toInsert[] = [
                'name'                     => $name,
                'phone'                    => $normalizedPhone ?: null,
                'type'                     => $validType,
                'default_shipping_area_id' => $areaId,
                'address'                  => $address ?: null,
                'notes'                    => $notes ?: null,
                'created_at'               => $now,
                'updated_at'               => $now,
            ];

            // Track in memory to catch duplicates within same file
            if ($normalizedPhone) $existingPhones[strtolower($normalizedPhone)] = true;
            $existingNames[strtolower($name)] = true;

            $imported++;

            // Batch insert every 200 rows to avoid memory issues
            if (count($toInsert) >= 200) {
                DB::table('customers')->insert($toInsert);
                $toInsert = [];
            }
        }

        // Insert remaining rows
        if (!empty($toInsert)) {
            DB::table('customers')->insert($toInsert);
        }

        $msg = "✓ Imported {$imported} customer(s).";
        if ($skipped) $msg .= " {$skipped} skipped (duplicates).";

        return redirect()->route('customers.index')
            ->with($skipped ? 'warning' : 'success', $msg);
    }

}