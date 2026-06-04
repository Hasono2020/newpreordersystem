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

        return $this->streamCsv('orders_export.csv', function ($out) use ($orders) {
            fputcsv($out, ['order_number','customer_name','customer_type','customer_phone',
                'trip','shipping_area','subtotal','discount','shipping_fee',
                'shipping_discount','total_amount','paid','balance_due','payment_status','created_at','notes']);
            foreach ($orders as $o) {
                fputcsv($out, [$o->order_number, $o->customer->name, $o->customer->type,
                    $o->customer->phone, $o->trip->name, $o->shippingArea?->name ?? '',
                    $o->subtotal, $o->discount_amount, $o->shipping_fee, $o->shipping_discount,
                    $o->total_amount, $o->deposit_paid, $o->total_amount - $o->deposit_paid,
                    $o->payment_status, $o->created_at->format('Y-m-d H:i'), $o->notes]);
            }
        });
    }

    public function exportOrderItems(Request $request)
    {
        $query = OrderItem::with('order.customer', 'order.trip', 'product', 'variant');
        if ($request->trip_id) {
            $query->whereHas('order', fn($q) => $q->where('trip_id', $request->trip_id));
        }
        $items = $query->latest()->get();

        return $this->streamCsv('order_items_export.csv', function ($out) use ($items) {
            fputcsv($out, ['order_number','customer_name','customer_type','trip',
                'product_name','product_code','color','size','quantity','unit_price','line_total','status','notes']);
            foreach ($items as $i) {
                fputcsv($out, [$i->order->order_number, $i->order->customer->name,
                    $i->order->customer->type, $i->order->trip->name,
                    $i->product->name, $i->product->product_code ?? '',
                    $i->variant?->color ?? '', $i->variant?->size ?? '',
                    $i->quantity, $i->unit_price, $i->line_total, $i->status, $i->notes]);
            }
        });
    }

    public function exportCustomers()
    {
        $customers = Customer::with('defaultShippingArea')
            ->withCount('orders')
            ->withSum('orders as total_spent', 'total_amount')
            ->orderBy('name')->get();

        return $this->streamCsv('customers_export.csv', function ($out) use ($customers) {
            fputcsv($out, ['name','phone','type','shipping_area','address','notes','total_orders','total_spent']);
            foreach ($customers as $c) {
                fputcsv($out, [
                    $c->name, $c->phone, $c->type,
                    $c->defaultShippingArea?->name ?? '',
                    $c->address, $c->notes,
                    $c->orders_count, $c->total_spent ?? 0,
                ]);
            }
        });
    }

    public function exportProducts(Request $request)
    {
        $query = Product::with('trip', 'supplier')->withSum(['orderItems as total_ordered' =>
            fn($q) => $q->whereNotIn('status', ['cancelled','sold_out'])], 'quantity');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $products = $query->orderBy('name')->get();

        return $this->streamCsv('products_export.csv', function ($out) use ($products) {
            fputcsv($out, ['trip','name','product_code','sku','brand','supplier','price','weight_gram','excluded_from_promo','status','total_ordered']);
            foreach ($products as $p) {
                fputcsv($out, [
                    $p->trip->name, $p->name,
                    $p->product_code ?? '', $p->sku ?? '', $p->brand ?? '',
                    $p->supplier?->name ?? '',
                    $p->price, $p->weight_gram,
                    $p->excluded_from_promo ? 'yes' : 'no',
                    $p->status, $p->total_ordered ?? 0,
                ]);
            }
        });
    }

    // ── Excel Order Import (matches Contoh.xlsx format) ──────────────

    /**
     * Download template Excel matching Contoh.xlsx format
     * Columns: No | Name | Phone | Area | Code | Color | Size | Price | DP | Date of DP | AN | Notes
     */
    public function orderImportTemplate()
    {
        $xml = $this->buildXlsx([
            ['No', 'Name', 'Phone', 'Area', 'Code', 'Color', 'Size', 'Price', 'DP', 'Date of DP', 'AN', 'Notes'],
            [1, 'JASMINE 7911', '08123456789', 'SURABAYA', 'NA_03', 'GREY', 'FZ', 169000, 500000, '2026-05-03', 'ORD-EXAMPLE', ''],
            [2, 'JASMINE 7911', '', '', 'NA_03', 'BROWN', 'FZ', 169000, '', '', '', ''],
            [3, 'JASMINE 7911', '', '', 'NA_03', 'NAVY', 'FZ', 169000, '', '', '', ''],
        ]);

        return Response::make($xml, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="order_import_template.xlsx"',
        ]);
    }

    /**
     * Import orders from Excel (matching Contoh.xlsx format)
     * Columns: No | Name | Phone | Area | Code | Color | Size | Price | DP | Date of DP | AN | Notes
     */
    public function importOrders(Request $request)
    {
        $request->validate([
            'file'    => 'required|file|max:5120',
            'trip_id' => 'required|exists:trips,id',
        ]);

        $trip = Trip::findOrFail($request->trip_id);
        $rows = $this->readXlsx($request->file('file')->getRealPath());

        if (empty($rows)) {
            return back()->with('error', 'Could not read file. Make sure it is a valid .xlsx file.');
        }

        // Skip header row
        array_shift($rows);

        $imported  = 0;
        $skipped   = 0;
        $errors    = [];
        $promoSvc  = app(\App\Services\PromoService::class);

        DB::transaction(function () use ($rows, $trip, &$imported, &$skipped, &$errors, $promoSvc) {
            $currentOrder    = null;
            $currentCustName = null;

            foreach ($rows as $rowIdx => $row) {
                // Columns: 0=No 1=Name 2=Phone 3=Area 4=Code 5=Color 6=Size 7=Price 8=DP 9=DateDP 10=AN 11=Notes
                $name  = trim($row[1] ?? '');
                $phone = trim($row[2] ?? '');
                $area  = trim($row[3] ?? '');
                $code  = strtoupper(trim($row[4] ?? ''));
                $color = trim($row[5] ?? '');
                $size  = trim($row[6] ?? '');
                $price = (float)($row[7] ?? 0);
                $dp    = (float)($row[8] ?? 0);
                $dpDate = trim($row[9] ?? '');
                $notes = trim($row[11] ?? '');

                if (empty($name) && empty($code)) continue;

                // Find or create customer
                if ($name && $name !== $currentCustName) {
                    // Find shipping area first (needed for customer default)
                    $shippingArea = null;
                    if ($area) {
                        $shippingArea = ShippingArea::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($area).'%'])->first();
                    }

                    // Use DB directly to bypass model validation on required fields
                    $customer = Customer::where('name', $name)->first();
                    if (!$customer) {
                        $customer = Customer::create([
                            'name'                     => $name,
                            'phone'                    => $phone ?: 'imported',
                            'type'                     => 'customer',
                            'default_shipping_area_id' => $shippingArea?->id,
                        ]);
                    } else {
                        // Update phone if missing
                        if ($phone && !$customer->phone) {
                            $customer->update(['phone' => $phone]);
                        }
                        // Update area if missing and we found one
                        if ($shippingArea && !$customer->default_shipping_area_id) {
                            $customer->update(['default_shipping_area_id' => $shippingArea->id]);
                        }
                    }

                    // Create new order for this customer
                    $currentOrder = Order::create([
                        'trip_id'          => $trip->id,
                        'customer_id'      => $customer->id,
                        'shipping_area_id' => $shippingArea?->id,
                        'created_by'       => Auth::id(),
                        'notes'            => $notes ?: null,
                    ]);

                    $currentCustName = $name;

                    // Record deposit if present
                    if ($dp > 0) {
                        $parsedDate = now()->format('Y-m-d');
                        try {
                            if ($dpDate) $parsedDate = \Carbon\Carbon::parse($dpDate)->format('Y-m-d');
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

                    $imported++;
                }

                // Add order item if code is present
                if ($currentOrder && $code) {
                    $product = Product::where('product_code', $code)
                        ->where('trip_id', $trip->id)
                        ->first();

                    if (!$product) {
                        $errors[] = "Row ".($rowIdx+2).": Product code '{$code}' not found in this trip — skipped.";
                        $skipped++;
                        continue;
                    }

                    // Find matching variant
                    $variant = null;
                    if ($color || $size) {
                        $variant = $product->variants()
                            ->when($color, fn($q) => $q->whereRaw('LOWER(color) = ?', [strtolower($color)]))
                            ->when($size,  fn($q) => $q->whereRaw('LOWER(size) = ?', [strtolower($size)]))
                            ->first();
                    }

                    $unitPrice = $price ?: ($variant ? $variant->final_price : $product->price);

                    $currentOrder->items()->create([
                        'product_id'         => $product->id,
                        'product_variant_id' => $variant?->id,
                        'quantity'           => 1,
                        'unit_price'         => $unitPrice,
                        'line_total'         => $unitPrice,
                        'status'             => 'pending',
                    ]);
                }

                // Recalc order after all items are added (done below after loop)
            }
        });

        // Recalculate totals for all newly imported orders
        $newOrders = Order::where('trip_id', $trip->id)
            ->whereDate('created_at', today())
            ->whereDoesntHave('items', fn($q) => $q->where('status', '!=', 'pending'))
            ->get();

        foreach ($newOrders as $order) {
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

        $msg = "Imported {$imported} orders.";
        if ($skipped) $msg .= " {$skipped} items skipped.";
        if ($errors)  $msg .= " Warnings: " . implode('; ', array_slice($errors, 0, 3));

        return redirect()->route('reports.index')->with('success', $msg);
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

    // ── Helpers ──────────────────────────────────────────────────────

    private function streamCsv(string $filename, callable $callback)
    {
        return Response::stream(function () use ($callback) {
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            $callback($out);
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Read xlsx file using native ZipArchive + SimpleXML (no package needed)
     * Returns array of rows (each row is array of cell values)
     */
    private function readXlsx(string $path): array
    {
        $rows = [];
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) return [];

            // Read shared strings
            $sharedStrings = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml) {
                $ss = simplexml_load_string($ssXml);
                foreach ($ss->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $val = '';
                        foreach ($si->r as $r) $val .= (string)($r->t ?? '');
                        $sharedStrings[] = $val;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }

            // Read sheet1
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            if (!$sheetXml) return [];

            $sheet = simplexml_load_string($sheetXml);
            $maxCol = 0;

            foreach ($sheet->sheetData->row as $row) {
                $rowData = [];
                $rowIdx  = (int)$row['r'] - 1;
                foreach ($row->c as $cell) {
                    // Parse column letter to index
                    preg_match('/^([A-Z]+)(\d+)$/', (string)$cell['r'], $m);
                    $colIdx = $this->colLetterToIndex($m[1]);
                    $maxCol = max($maxCol, $colIdx);

                    $type = (string)($cell['t'] ?? '');
                    $v    = (string)($cell->v ?? '');

                    if ($type === 's') {
                        $rowData[$colIdx] = $sharedStrings[(int)$v] ?? '';
                    } elseif ($type === 'str' || $type === 'inlineStr') {
                        $rowData[$colIdx] = $v;
                    } else {
                        // Number or date — return as-is
                        $rowData[$colIdx] = is_numeric($v) ? (strpos($v, '.') !== false ? (float)$v : (int)$v) : $v;
                    }
                }
                // Fill missing columns with empty string
                for ($i = 0; $i <= $maxCol; $i++) {
                    if (!array_key_exists($i, $rowData)) $rowData[$i] = '';
                }
                ksort($rowData);
                $rows[$rowIdx] = array_values($rowData);
            }
            ksort($rows);
            return array_values($rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function colLetterToIndex(string $col): int
    {
        $col = strtoupper($col);
        $idx = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $idx - 1;
    }

    /**
     * Build a minimal valid .xlsx file from a 2D array
     */
    private function buildXlsx(array $data): string
    {
        $sharedStrings = [];
        $ssIndex = [];

        // Collect all unique strings
        foreach ($data as $row) {
            foreach ($row as $cell) {
                $s = (string)$cell;
                if (!is_numeric($cell) && $s !== '' && !isset($ssIndex[$s])) {
                    $ssIndex[$s] = count($sharedStrings);
                    $sharedStrings[] = $s;
                }
            }
        }

        // Build sheet XML
        $sheetRows = '';
        foreach ($data as $ri => $row) {
            $rowNum = $ri + 1;
            $cells  = '';
            foreach ($row as $ci => $cell) {
                $col  = $this->indexToColLetter($ci);
                $ref  = $col . $rowNum;
                $s    = (string)$cell;
                if ($s === '') {
                    $cells .= "<c r=\"{$ref}\"/>";
                } elseif (is_numeric($cell) && $s !== '') {
                    $cells .= "<c r=\"{$ref}\"><v>{$cell}</v></c>";
                } else {
                    $idx   = $ssIndex[$s];
                    $cells .= "<c r=\"{$ref}\" t=\"s\"><v>{$idx}</v></c>";
                }
            }
            $sheetRows .= "<row r=\"{$rowNum}\">{$cells}</row>";
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetData>' . $sheetRows . '</sheetData></worksheet>';

        // Build sharedStrings XML
        $ssEntries = '';
        foreach ($sharedStrings as $str) {
            $escaped  = htmlspecialchars($str, ENT_XML1, 'UTF-8');
            $ssEntries .= "<si><t>{$escaped}</t></si>";
        }
        $ssCount   = count($sharedStrings);
        $ssXml     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.$ssCount.'" uniqueCount="'.$ssCount.'">'.$ssEntries.'</sst>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Orders" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml"  ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';

        $dotRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

        // Write to temp zip
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',          $contentTypes);
        $zip->addFromString('_rels/.rels',                   $dotRels);
        $zip->addFromString('xl/workbook.xml',               $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels',    $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml',      $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml',          $ssXml);
        $zip->close();

        $content = file_get_contents($tmp);
        unlink($tmp);
        return $content;
    }

    private function indexToColLetter(int $idx): string
    {
        $letter = '';
        $idx++;
        while ($idx > 0) {
            $idx--;
            $letter = chr(65 + ($idx % 26)) . $letter;
            $idx    = intval($idx / 26);
        }
        return $letter;
    }
}
