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

            foreach ($o->items as $item) {
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
                // Only show DP / Waktu Order on first item row per order
                $dp    = '';
                $tglDp = '';
                $waktuOrder = '';
            }

            // If order has no items
            if ($o->items->isEmpty()) {
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
     */
    public function importOrders(Request $request)
    {
        $request->validate([
            'file'    => 'required|file|max:51200',
            'trip_id' => 'required|exists:trips,id',
        ]);

        // Large imports (10k+ rows) need more time and memory
        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');
        // Disable query log to avoid memory bloat on big batch imports
        DB::connection()->disableQueryLog();

        $trip = Trip::findOrFail($request->trip_id);
        $rows = $this->readXlsx($request->file('file')->getRealPath());
        if (empty($rows)) {
            return back()->with('error', 'Could not read the file. Make sure it is a valid .xlsx file.');
        }

        array_shift($rows); // remove header

        // Remove completely blank rows
        $rows = array_values(array_filter($rows, fn($r) =>
            !empty(trim((string)($r[2] ?? ''))) || !empty(trim((string)($r[6] ?? '')))
        ));

        if (empty($rows)) {
            return back()->with('error', 'The file has no data rows.');
        }

        // ── Pre-load ALL products for this trip into memory (for fast validation) ──
        $products = Product::where('trip_id', $trip->id)
            ->with('variants')
            ->get()
            ->keyBy(fn($p) => strtoupper($p->product_code ?? ''));

        $shippingAreas = \App\Models\ShippingArea::all()
            ->keyBy(fn($a) => strtolower(trim($a->name)));

        $shippingAreasById = $shippingAreas->keyBy('id'); // for fast lookup by ID during import

        // Pre-load CS agents (match by name, case-insensitive)
        $csAgents = \App\Models\CsAgent::all()
            ->keyBy(fn($a) => strtolower(trim($a->name)));

        // ── Full validation pass — check EVERY row before importing anything ──
        $errors = [];
        foreach ($rows as $i => $row) {
            $lineNum = $i + 2;
            $name    = trim((string)($row[2] ?? ''));
            $csName  = trim((string)($row[3] ?? ''));   // IG/WA = CS agent
            $code    = strtoupper(trim((string)($row[6] ?? '')));
            $color   = trim((string)($row[7] ?? ''));
            $size    = trim((string)($row[8] ?? ''));

            if (empty($name) && empty($code)) continue; // skip truly blank rows

            if (empty($name)) {
                $errors[] = "Row {$lineNum}: Name is required.";
                continue;
            }
            if (empty($csName)) {
                $errors[] = "Row {$lineNum} ({$name}): IG/WA (Customer Service) is required.";
                continue;
            }
            if (!isset($csAgents[strtolower($csName)])) {
                $errors[] = "Row {$lineNum} ({$name}): CS agent '{$csName}' not found. Add them in CS Agents first.";
                continue;
            }
            if (empty($code)) {
                $errors[] = "Row {$lineNum} ({$name}): Product Code is required.";
                continue;
            }

            $product = $products->get($code);
            if (!$product) {
                $errors[] = "Row {$lineNum} ({$name}): Code '{$code}' not found in trip '{$trip->name}'.";
                continue;
            }

            if ($color || $size) {
                $variantFound = $product->variants->first(function ($v) use ($color, $size) {
                    $colorMatch = !$color || strtolower($v->color ?? '') === strtolower($color);
                    $sizeMatch  = !$size  || strtolower($v->size  ?? '') === strtolower($size);
                    return $colorMatch && $sizeMatch;
                });
                if (!$variantFound) {
                    $errors[] = "Row {$lineNum} ({$name}): Variant '{$color}/{$size}' not found for code '{$code}'.";
                }
            }
        }

        if (!empty($errors)) {
            return back()->with('import_errors', $errors)
                         ->with('error', count($errors) . ' error(s) found — fix all issues before importing:');
        }

        // ── Pre-load customer data into memory ──────────────────────────────
        $existingPhones = DB::table('customers')->whereNotNull('phone')
            ->pluck('id', 'phone')
            ->mapWithKeys(fn($id, $p) => [strtolower(trim($p)) => $id])->toArray();

        $existingNames = DB::table('customers')->pluck('id', 'name')
            ->mapWithKeys(fn($id, $n) => [strtolower(trim($n)) => $id])->toArray();

        // Pre-load customer types for promo calculation
        $customerTypes = DB::table('customers')->pluck('type', 'id')->toArray();

        // Pre-load promo rules for this trip (in memory — no DB calls per order)
        $promoRules = \App\Models\PromoRule::where('is_active', true)
            ->where(fn($q) => $q->where('trip_id', $trip->id)->orWhereNull('trip_id'))
            ->orderByDesc('min_items')
            ->get();

        // ── Import pass — fast batch inserts ─────────────────────────────
        $imported  = 0;
        $skipped   = 0;
        $baseTime  = now();
        $now       = $baseTime->toDateTimeString();
        $createdBy = Auth::id();

        $ordersBatch    = [];
        $itemsBatch     = [];
        $paymentsBatch  = [];

        foreach ($rows as $rowIdx => $row) {
            $name    = trim((string)($row[2] ?? ''));
            $csName  = trim((string)($row[3] ?? ''));   // IG/WA = CS agent
            $contact = trim((string)($row[4] ?? ''));   // NO HP = customer phone
            $area    = trim((string)($row[5] ?? ''));
            $code    = strtoupper(trim((string)($row[6] ?? '')));
            $color   = trim((string)($row[7] ?? ''));
            $size    = trim((string)($row[8] ?? ''));
            $price   = (float)($row[9] ?? 0);
            $dp      = (float)($row[10] ?? 0);
            $dpRaw   = $row[11] ?? '';
            $an      = trim((string)($row[12] ?? ''));
            $ketPost = trim((string)($row[13] ?? ''));

            // Resolve CS agent by name (case-insensitive); blank/unknown = null
            $csAgentId = $csName !== '' ? ($csAgents[strtolower($csName)]->id ?? null) : null;

            if (empty($name) || empty($code)) continue;

            // Find or create customer
            $normalizedPhone = Customer::normalizePhone($contact);
            $customerId = null;
            if ($normalizedPhone && isset($existingPhones[strtolower($normalizedPhone)])) {
                $customerId = $existingPhones[strtolower($normalizedPhone)];
            } elseif (isset($existingNames[strtolower($name)])) {
                $customerId = $existingNames[strtolower($name)];
            } else {
                // Resolve area for new customer
                $areaKey = strtolower($area);
                $areaId  = $shippingAreas[$areaKey]?->id ?? null;
                if (!$areaId && $area) {
                    foreach ($shippingAreas as $k => $a) {
                        if (str_contains($k, $areaKey) || str_contains($areaKey, $k)) {
                            $areaId = $a->id; break;
                        }
                    }
                }
                $customerId = DB::table('customers')->insertGetId([
                    'name'                     => $name,
                    'phone'                    => $normalizedPhone ?: null,
                    'type'                     => 'customer',
                    'default_shipping_area_id' => $areaId,
                    'created_at'               => $now,
                    'updated_at'               => $now,
                ]);
                if ($normalizedPhone) $existingPhones[strtolower($normalizedPhone)] = $customerId;
                $existingNames[strtolower($name)] = $customerId;
            }

            // Resolve shipping area
            $areaKey = strtolower($area);
            $areaId  = $shippingAreas[$areaKey]?->id ?? null;
            if (!$areaId && $area) {
                foreach ($shippingAreas as $k => $a) {
                    if (str_contains($k, $areaKey) || str_contains($areaKey, $k)) {
                        $areaId = $a->id; break;
                    }
                }
            }

            // Find product and variant
            $product = $products[$code] ?? null;
            if (!$product) { $skipped++; continue; }

            $variant   = null;
            $unitPrice = $price > 0 ? $price : $product->price;
            if ($color || $size) {
                foreach ($product->variants as $v) {
                    $colorMatch = !$color || strtolower($v->color ?? '') === strtolower($color);
                    $sizeMatch  = !$size  || strtolower($v->size ?? '')  === strtolower($size);
                    if ($colorMatch && $sizeMatch) { $variant = $v; break; }
                }
                if ($variant && !$price) {
                    $unitPrice = $variant->final_price ?? $product->price;
                }
            }

            // ── Inline promo + shipping calculation (no DB queries) ────────
            $customerType = $customerTypes[$customerId] ?? 'customer';
            $weightGram   = $product->weight_gram ?? 0;

            // Shipping fee
            $shippingArea   = $areaId ? $shippingAreasById->get($areaId) : null;
            $shippingFee    = $shippingArea ? $shippingArea->calcShippingFee($weightGram) : 0;
            $chargeableKg   = \App\Models\ShippingArea::calcChargeableKg($weightGram);

            // Promo — check all pre-loaded rules (pure PHP, no DB)
            $isExcluded   = $product->excluded_from_promo ?? false;
            $eligibleQty  = $isExcluded ? 0 : 1;
            $bestDiscount = 0;
            $bestSubsidy  = 0;

            foreach ($promoRules as $rule) {
                if (!$rule->appliesTo($customerType, $eligibleQty)) continue;
                $calc    = $rule->calculateDiscount($eligibleQty);
                $benefit = $calc['discount'] + $calc['max_shipping_subsidy'];
                if ($benefit > $bestDiscount + $bestSubsidy) {
                    $bestDiscount = $calc['discount'];
                    $bestSubsidy  = $calc['max_shipping_subsidy'];
                }
            }
            $shippingDiscount = min($shippingFee, $bestSubsidy);
            $totalAmount      = max(0, $unitPrice - $bestDiscount + $shippingFee - $shippingDiscount);
            // ──────────────────────────────────────────────────────────────

            // FIFO timestamp based on row position
            $orderedAt = $baseTime->copy()->addSeconds($rowIdx)->toDateTimeString();
            $notes     = implode(' | ', array_filter([$an, $ketPost])) ?: null;

            $ordersBatch[] = [
                'trip_id'              => $trip->id,
                'customer_id'          => $customerId,
                'shipping_area_id'     => $areaId,
                'cs_agent_id'          => $csAgentId,
                'notes'                => $notes,
                'ordered_at'           => $orderedAt,
                'created_by'           => $createdBy,
                'subtotal'             => $unitPrice,
                'discount_amount'      => $bestDiscount,
                'shipping_fee'         => $shippingFee,
                'shipping_discount'    => $shippingDiscount,
                'shipping_weight_gram' => $weightGram,
                'shipping_kg_charged'  => $chargeableKg,
                'total_amount'         => $totalAmount,
                'deposit_paid'         => $dp > 0 ? $dp : 0,
                'payment_status'       => $dp > 0 ? 'partial' : 'unpaid',
                'created_at'           => $now,
                'updated_at'           => $now,
            ];

            $itemsBatch[]  = ['_rowIdx' => count($ordersBatch) - 1,
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity'           => 1,
                'unit_price'         => $unitPrice,
                'line_total'         => $unitPrice,
                'status'             => 'pending',
                'created_at'         => $now,
                'updated_at'         => $now,
            ];

            // Parse DP date — handles Excel serials, DD/MM/YYYY, DD-MM-YY, text dates
            if ($dp > 0) {
                $dpDate = substr($now, 0, 10); // default today
                if ($dpRaw !== '' && $dpRaw !== null) {
                    $dpDate = $this->parseDateValue($dpRaw, $dpDate);
                }
                $paymentsBatch[] = ['_rowIdx' => count($ordersBatch) - 1,
                    'amount' => $dp, 'type' => 'deposit', 'method' => 'transfer',
                    'paid_at' => $dpDate, 'recorded_by' => $createdBy,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            $imported++;

            // Flush every 500 orders
            if (count($ordersBatch) >= 500) {
                $this->flushOrderBatch($ordersBatch, $itemsBatch, $paymentsBatch);
                $ordersBatch = []; $itemsBatch = []; $paymentsBatch = [];
            }
        }

        if (!empty($ordersBatch)) {
            $this->flushOrderBatch($ordersBatch, $itemsBatch, $paymentsBatch);
        }

        return redirect()
            ->route('orders.index', ['trip_id' => $trip->id])
            ->with('success', "✓ Imported {$imported} order(s) for '{$trip->name}'. Promo and shipping applied per order." . ($skipped ? " {$skipped} skipped (product not found)." : ''));
    }

    /**
     * Parse any date value from Excel — handles:
     *   - Excel serial numbers (e.g. 46145 → 2026-05-03)
     *   - DD/MM/YYYY or DD/MM/YY  (slash-separated)
     *   - DD-MM-YYYY or DD-MM-YY  (dash-separated, day first)
     *   - YYYY-MM-DD              (ISO format)
     *   - Text like "11-May-26"   (Carbon parse fallback)
     * Returns YYYY-MM-DD string or $fallback on failure.
     */
    private function parseDateValue(mixed $raw, string $fallback): string
    {
        $s = trim((string)$raw);
        if ($s === '') return $fallback;

        // Excel serial number (days since 1900-01-00, modern dates > 40000)
        if (is_numeric($s) && (int)$s > 40000) {
            try {
                $r = \Carbon\Carbon::createFromTimestamp(((int)$s - 25569) * 86400)->utc()->format('Y-m-d');
                if ($this->isValidYmd($r)) return $r;
            } catch (\Exception $e) {}
        }

        // Small numeric (e.g. serial < 40000) — not a usable date
        if (is_numeric($s)) return $fallback;

        // Slash-separated: DD/MM/YYYY or DD/MM/YY  (day first)
        if (str_contains($s, '/')) {
            $p = explode('/', $s);
            if (count($p) === 3) {
                [$d, $m, $y] = [(int)$p[0], (int)$p[1], (int)$p[2]];
                if ($y < 100) $y += 2000;
                if (checkdate($m, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $m, $d);
            }
        }

        // Dash-separated: try multiple interpretations, accept first valid one
        if (str_contains($s, '-')) {
            $p = explode('-', $s);
            if (count($p) === 3) {
                $a = (int)$p[0]; $b = (int)$p[1]; $c = (int)$p[2];

                // ISO: YYYY-MM-DD
                if ($a > 1900 && checkdate($b, $c, $a))
                    return sprintf('%04d-%02d-%02d', $a, $b, $c);

                // DD-MM-YY / DD-MM-YYYY
                $y = $c < 100 ? $c + 2000 : $c;
                if ($y >= 1900 && checkdate($b, $a, $y))
                    return sprintf('%04d-%02d-%02d', $y, $b, $a);

                // MM-DD-YY / MM-DD-YYYY
                if ($y >= 1900 && checkdate($a, $b, $y))
                    return sprintf('%04d-%02d-%02d', $y, $a, $b);
            }
        }

        // Text fallback (e.g. "11-May-26") — validate after parsing
        try {
            $r = \Carbon\Carbon::parse($s)->format('Y-m-d');
            if ($this->isValidYmd($r)) return $r;
        } catch (\Exception $e) {}

        return $fallback;
    }

    private function isValidYmd(string $d): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
        return (int)$m[1] >= 1900 && (int)$m[1] <= 2100 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    private function flushOrderBatch(array &$orders, array &$items, array &$payments): void
    {
        if (empty($orders)) return;
        $count = count($orders);

        // Generate unique order numbers using random hex
        foreach ($orders as $i => &$order) {
            $order['order_number'] = 'ORD-' . strtoupper(bin2hex(random_bytes(5)));
        }
        unset($order);

        DB::table('orders')->insert($orders);

        // MySQL guarantees contiguous auto-increment IDs for a single INSERT
        // lastInsertId() returns the FIRST inserted ID
        $firstId = (int) DB::getPdo()->lastInsertId();
        $insertedIds = range($firstId, $firstId + $count - 1);

        // Insert items
        $itemsToInsert = [];
        foreach ($items as $item) {
            $idx = $item['_rowIdx'];
            if (isset($insertedIds[$idx])) {
                unset($item['_rowIdx']);
                $item['order_id'] = $insertedIds[$idx];
                $itemsToInsert[]  = $item;
            }
        }
        if ($itemsToInsert) DB::table('order_items')->insert($itemsToInsert);

        // Insert payments
        $paymentsToInsert = [];
        foreach ($payments as $pay) {
            $idx = $pay['_rowIdx'];
            if (isset($insertedIds[$idx])) {
                unset($pay['_rowIdx']);
                $pay['order_id']    = $insertedIds[$idx];
                $paymentsToInsert[] = $pay;
            }
        }
        if ($paymentsToInsert) DB::table('payments')->insert($paymentsToInsert);
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