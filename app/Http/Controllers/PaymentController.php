<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    use \App\Traits\HandlesXlsx;

    /**
     * Payments home: outstanding balances + payment log.
     */
    public function index(Request $request)
    {
        if (!Auth::user()->hasPermission('payments.view')) {
            abort(403, 'You do not have permission to view payments.');
        }

        $trips = Trip::orderByDesc('id')->get();
        $tripId = $request->trip_id ?: ($trips->first()->id ?? null);
        $tab = $request->get('tab', 'outstanding');
        $search = trim($request->get('search', ''));
        $createdByFilter = !Auth::user()->isOwnDataOnly() ? $request->get('created_by', '') : '';

        // ── Outstanding: pure aggregate query — only_full_group_by safe ──────
        $outstanding = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50);
        if ($tripId) {
            $tid = (int) $tripId; // safe int for subquery interpolation
            $query = DB::table('orders')
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->select([
                    'orders.customer_id',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone',
                    'customers.type as customer_type',
                    DB::raw('COUNT(orders.id) as order_count'),
                    DB::raw('SUM(orders.total_amount) as total_ordered'),
                    DB::raw('SUM(orders.deposit_paid) as total_paid'),
                    DB::raw('(SUM(orders.total_amount) - SUM(orders.deposit_paid)) as balance_due'),
                    DB::raw('COUNT(DISTINCT orders.created_by) as creator_count'),
                    DB::raw('MAX(creators.name) as creator_name'),
                    // Per-customer payment verification counts (non-voided payments on this customer's orders in this trip).
                    // $tid is an int cast of the trip id — safe to interpolate; statuses are hardcoded literals.
                    DB::raw("(SELECT COUNT(*) FROM payments p JOIN orders po ON po.id = p.order_id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tid} AND p.voided_at IS NULL) as pay_total"),
                    DB::raw("(SELECT COUNT(*) FROM payments p JOIN orders po ON po.id = p.order_id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tid} AND p.voided_at IS NULL AND p.verification_status = 'verified') as pay_verified"),
                    DB::raw("(SELECT COUNT(*) FROM payments p JOIN orders po ON po.id = p.order_id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tid} AND p.voided_at IS NULL AND p.verification_status = 'unverified') as pay_unverified"),
                    DB::raw("(SELECT COUNT(*) FROM payments p JOIN orders po ON po.id = p.order_id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tid} AND p.voided_at IS NULL AND p.verification_status = 'disputed') as pay_disputed"),
                ])
                ->join('users as creators', 'creators.id', '=', 'orders.created_by')
                ->where('orders.trip_id', $tripId)
                ->where('orders.payment_status', '!=', 'paid')
                ->when(Auth::user()->isOwnDataOnly(), fn($q) => $q->where('orders.created_by', Auth::id()))
                ->when($createdByFilter, fn($q) => $q->where('orders.created_by', $createdByFilter))
                ->when($search && !$this->isMoneySearch($search), fn($q) => $q->where(fn($w) =>
                    $w->where('customers.name', 'like', "%{$search}%")
                      ->orWhere('customers.phone', 'like', "%{$search}%")
                ))
                ->groupBy(
                    'orders.customer_id',
                    'customers.name',
                    'customers.phone',
                    'customers.type'
                )
                // Note: created_by_names uses GROUP_CONCAT so no groupBy needed
                ->having('balance_due', '>', 0);

            // A purely numeric search matches ANY of the three amounts:
            // total ordered, total paid, or balance due.
            if ($search && $this->isMoneySearch($search)) {
                $amt = (int) preg_replace('/[^0-9]/', '', $search);
                $query->havingRaw(
                    '(SUM(orders.total_amount) = ? OR SUM(orders.deposit_paid) = ? OR (SUM(orders.total_amount) - SUM(orders.deposit_paid)) = ?)',
                    [$amt, $amt, $amt]
                );
            }

            $query->orderByDesc('balance_due');

            $outstanding = $query->paginate(50, ['*'], 'outstanding_page')
                ->withQueryString();

        }

        // ── Payment log: recent payments (optionally scoped to trip) ────
        $logQuery = Payment::with(['order.customer', 'order.trip', 'order.createdBy', 'recordedBy', 'verifiedBy'])
            ->orderByDesc('paid_at')->orderByDesc('id');
        if ($tripId) {
            $logQuery->whereHas('order', fn($q) => $q->where('trip_id', $tripId));
        }
        // Staff with own_data only see payments for orders they created
        if (Auth::user()->isOwnDataOnly()) {
            $logQuery->whereHas('order', fn($q) => $q->where('created_by', Auth::id()));
        }
        if ($search) {
            // If the search looks like money (digits, dots, commas, optional 'Rp'), match the amount too.
            $numeric = preg_replace('/[^0-9]/', '', $search);
            $logQuery->where(function ($q) use ($search, $numeric) {
                $q->whereHas('order.customer', fn($c) =>
                        $c->where('name', 'like', "%{$search}%")
                          ->orWhere('phone', 'like', "%{$search}%"))
                  ->orWhereHas('order', fn($o) => $o->where('order_number', 'like', "%{$search}%"))
                  ->orWhere('reference', 'like', "%{$search}%");
                if ($numeric !== '' && strlen($numeric) >= 3) {
                    $q->orWhere('amount', (int) $numeric);
                }
            });
        }

        $verificationFilter = $request->get('verification_status', '');
        if ($verificationFilter) {
            $logQuery->where('verification_status', $verificationFilter);
        }

        // Filter by order creator (admin/finance only — staff already scoped)
        // createdByFilter applied to log below
        if ($createdByFilter) {
            $logQuery->whereHas('order', fn($q) => $q->where('created_by', $createdByFilter));
        }

        $log = $logQuery->paginate(50)->withQueryString();

        // Batch metadata: for each batch_id on this page, total amount + order count.
        // Lets finance see that N payment rows came from ONE customer transfer.
        $batchIds = collect($log->items())->pluck('batch_id')->filter()->unique()->values();
        $batchMeta = [];
        if ($batchIds->isNotEmpty()) {
            $batchMeta = Payment::whereIn('batch_id', $batchIds)
                ->whereNull('voided_at')
                ->selectRaw('batch_id, COUNT(*) as cnt, SUM(amount) as total')
                ->groupBy('batch_id')
                ->get()
                ->keyBy('batch_id')
                ->map(fn($r) => ['count' => (int) $r->cnt, 'total' => (float) $r->total])
                ->all();
        }

        // Verification counts for the tab badges and summary bar (scoped to trip)
        $vcBase = Payment::whereHas('order', fn($q) => $q->where('trip_id', $tripId));
        $vcBase->whereNull('voided_at');
        if (Auth::user()->isOwnDataOnly()) {
            $vcBase->whereHas('order', fn($q) => $q->where('created_by', Auth::id()));
        }
        $verificationCounts = [
            'unverified'      => (clone $vcBase)->where('verification_status', 'unverified')->count(),
            'verified'        => (clone $vcBase)->where('verification_status', 'verified')->count(),
            'disputed'        => (clone $vcBase)->where('verification_status', 'disputed')->count(),
            'verified_amount' => (clone $vcBase)->where('verification_status', 'verified')->sum('amount'),
        ];

        // ── Ready to Pack: customers whose orders in this trip are ALL paid + verified ──
        $readyToPack = collect();
        $readyCount  = 0;
        if ($tripId) {
            $tidInt = (int) $tripId;
            $custQuery = DB::table('orders')
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->where('orders.trip_id', $tidInt)
                ->when(Auth::user()->isOwnDataOnly(), fn($q) => $q->where('orders.created_by', Auth::id()))
                ->when($createdByFilter, fn($q) => $q->where('orders.created_by', $createdByFilter))
                ->when($search && !$this->isMoneySearch($search), fn($q) => $q->where(fn($w) =>
                    $w->where('customers.name', 'like', "%{$search}%")
                      ->orWhere('customers.phone', 'like', "%{$search}%")))
                ->groupBy('orders.customer_id', 'customers.name', 'customers.phone')
                ->select([
                    'orders.customer_id',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone',
                    DB::raw('COUNT(orders.id) as order_count'),
                    DB::raw('SUM(orders.total_amount) as total_amount'),
                    DB::raw("SUM(CASE WHEN orders.payment_status != 'paid' THEN 1 ELSE 0 END) as unpaid_count"),
                    DB::raw("(SELECT COUNT(DISTINCT po.id) FROM orders po JOIN payments p ON p.order_id = po.id WHERE po.customer_id = orders.customer_id AND po.trip_id = {$tidInt} AND p.voided_at IS NULL AND p.verification_status != 'verified') as unverified_order_count"),
                    DB::raw('MAX(orders.invoice_printed_at) as printed_at'),
                ])
                ->having('unpaid_count', '=', 0)
                ->having('unverified_order_count', '=', 0)
                ->when($search && $this->isMoneySearch($search), fn($q) =>
                    $q->havingRaw('SUM(orders.total_amount) = ?', [(int) preg_replace('/[^0-9]/', '', $search)]))
                ->orderBy('customers.name');

            $readyToPack = $custQuery->paginate(50, ['*'], 'pack_page')->withQueryString();
            $readyCount  = $readyToPack->total();
        }

        $staffList = \App\Models\User::where('is_active', true)->orderBy('name')->get(['id','name','role']);
        // ── Overpaid / Credit: customers who paid MORE than their order total (owed a refund) ──
        $overpaid = collect();
        if ($tripId) {
            $tidC = (int) $tripId;
            $overpaid = DB::table('orders')
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->where('orders.trip_id', $tidC)
                ->when(Auth::user()->isOwnDataOnly(), fn($q) => $q->where('orders.created_by', Auth::id()))
                ->when($createdByFilter, fn($q) => $q->where('orders.created_by', $createdByFilter))
                ->when($search, fn($q) => $q->where(fn($w) =>
                    $w->where('customers.name', 'like', "%{$search}%")
                      ->orWhere('customers.phone', 'like', "%{$search}%")))
                ->groupBy('orders.customer_id', 'customers.name', 'customers.phone')
                ->select([
                    'orders.customer_id',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone',
                    DB::raw('SUM(orders.total_amount) as total_ordered'),
                    DB::raw('SUM(orders.deposit_paid) as total_paid'),
                    DB::raw('(SUM(orders.deposit_paid) - SUM(orders.total_amount)) as credit'),
                ])
                ->having('credit', '>', 0)
                ->orderByDesc('credit')
                ->get();
        }

        return view('payments.index', compact('trips', 'tripId', 'tab', 'outstanding', 'log', 'search', 'verificationFilter', 'verificationCounts', 'createdByFilter', 'staffList', 'readyToPack', 'readyCount', 'batchMeta', 'overpaid'));
    }

    /**
     * Show the record-payment screen for one customer (their unpaid orders).
     */
    public function createForCustomer(Request $request, Customer $customer)
    {
        if (!Auth::user()->hasPermission('payments.record')) {
            abort(403, 'You do not have permission to record payments.');
        }

        $tripId = $request->trip_id;
        abort_if(!$tripId, 404, 'Trip is required.');

        $trip = Trip::findOrFail($tripId);

        // Unpaid orders for this customer in this trip, oldest first (FIFO allocation)
        $orders = Order::where('customer_id', $customer->id)
            ->where('trip_id', $tripId)
            ->where('payment_status', '!=', 'paid')
            ->orderBy('ordered_at')->orderBy('id')
            ->get();

        $totalDue = $orders->sum(fn($o) => max(0, $o->total_amount - $o->deposit_paid));

        return view('payments.create', compact('customer', 'trip', 'orders', 'totalDue'));
    }

    /**
     * Store a lump-sum payment, allocated across the customer's orders.
     * Each order gets its own payment row sharing a batch_id.
     */
    /**
     * True if the search string looks like a money amount (digits only after
     * stripping Rp / dots / commas / spaces) of at least 3 digits.
     */
    private function isMoneySearch(?string $search): bool
    {
        if (!$search) return false;
        $digits = preg_replace('/[^0-9]/', '', $search);
        // Must be all digits once formatting is removed, and long enough to be an amount
        $stripped = str_ireplace(['rp', ' ', '.', ','], '', $search);
        return ctype_digit($stripped) && strlen($digits) >= 3;
    }

    public function store(Request $request)
    {
        if (!Auth::user()->hasPermission('payments.record')) {
            abort(403, 'You do not have permission to record payments.');
        }

        $data = $request->validate([
            'customer_id'          => 'required|exists:customers,id',
            'trip_id'              => 'required|exists:trips,id',
            'method'               => 'nullable|string|max:50',
            'reference'            => 'nullable|string|max:100',
            'paid_at'              => 'required|date',
            'notes'                => 'nullable|string',
            'allocations'          => 'required|array|min:1',
            'allocations.*.order_id' => 'required|exists:orders,id',
            'allocations.*.amount'   => 'required|numeric|min:0',
        ]);

        // Filter to allocations with a positive amount
        $allocations = collect($data['allocations'])
            ->filter(fn($a) => (float) $a['amount'] > 0)
            ->values();

        if ($allocations->isEmpty()) {
            return back()->withInput()->with('error', 'Enter at least one amount to allocate.');
        }

        $batchId = (string) Str::uuid();
        $affectedOrderIds = [];

        DB::transaction(function () use ($allocations, $data, $batchId, &$affectedOrderIds) {
            foreach ($allocations as $alloc) {
                $order = Order::where('id', $alloc['order_id'])
                    ->where('trip_id', $data['trip_id'])
                    ->where('customer_id', $data['customer_id'])
                    ->first();
                if (!$order) continue;

                $order->payments()->create([
                    'batch_id'    => $batchId,
                    'amount'      => $alloc['amount'],
                    'type'        => 'partial',
                    'method'      => $data['method'] ?? 'transfer',
                    'reference'   => $data['reference'] ?? null,
                    'paid_at'     => $data['paid_at'],
                    'notes'       => $data['notes'] ?? null,
                    'recorded_by' => Auth::id(),
                ]);
                $affectedOrderIds[] = $order->id;
            }

            // Recalculate each affected order's payment status
            foreach (array_unique($affectedOrderIds) as $oid) {
                $this->recalcOrderPayment(Order::find($oid));
            }
        });

        $total = $allocations->sum(fn($a) => (float) $a['amount']);
        $orderCount = count(array_unique($affectedOrderIds));
        $customer = Customer::find($data['customer_id']);

        \App\Models\ActivityLog::record(
            'payment.recorded',
            'Recorded Rp ' . number_format($total, 0, ',', '.') . " across {$orderCount} order(s) for " . ($customer->name ?? 'customer'),
            'customer',
            $data['customer_id']
        );

        return redirect()->route('payments.index', ['trip_id' => $data['trip_id']])
            ->with('success', 'Payment of Rp ' . number_format($total, 0, ',', '.') .
                ' recorded across ' . $orderCount . ' order(s).');
    }

    /**
     * Void an entire payment batch (all rows sharing the batch_id),
     * or a single payment if it has no batch.
     */
    public function voidBatch(Request $request, string $batchId)
    {
        if (!Auth::user()->hasPermission('payments.void')) {
            abort(403, 'You do not have permission to void payments.');
        }

        $payments = Payment::where('batch_id', $batchId)->whereNull('voided_at')->get();
        if ($payments->isEmpty()) {
            return back()->with('error', 'No active payments found for this batch.');
        }

        $voidedTotal = $payments->sum('amount');
        $voidedCount = $payments->count();
        $reason = $request->input('void_reason', 'Batch voided');

        $affectedOrderIds = [];
        DB::transaction(function () use ($payments, $request, &$affectedOrderIds) {
            foreach ($payments as $payment) {
                $payment->update([
                    'voided_at'   => now(),
                    'voided_by'   => Auth::id(),
                    'void_reason' => $request->input('void_reason', 'Batch voided'),
                ]);
                $affectedOrderIds[] = $payment->order_id;
            }
            foreach (array_unique($affectedOrderIds) as $oid) {
                $this->recalcOrderPayment(Order::find($oid));
            }
        });

        \App\Models\ActivityLog::record(
            'payment.batch_voided',
            "Voided payment batch ({$voidedCount} payment(s), Rp " . number_format($voidedTotal, 0, ',', '.') . ") — reason: {$reason}",
            'customer',
            null
        );

        return back()->with('success', 'Payment batch voided. Affected order balances restored.');
    }


    /**
     * Export payments for a trip — two-sheet xlsx:
     *   Sheet 1 — Outstanding Balances
     *   Sheet 2 — Payment Log
     */
    public function export(Request $request)
    {
        if (!Auth::user()->hasPermission('payments.export')) {
            abort(403);
        }

        $trips  = Trip::orderByDesc('id')->get();
        $tripId = $request->trip_id ?: ($trips->first()->id ?? null);
        $trip   = Trip::find($tripId);
        $label  = $trip ? preg_replace('/[^\w\-]/', '_', $trip->name) : 'trip';

        // ── Sheet 1: Outstanding Balances ─────────────────────────────
        $sheet1 = [
            ['Customer', 'Phone', 'Type', 'Orders', 'Total Ordered (Rp)', 'Total Paid (Rp)', 'Balance Due (Rp)'],
        ];
        $orders = Order::with('customer')
            ->where('trip_id', $tripId)
            ->where('payment_status', '!=', 'paid')
            ->get()
            ->groupBy('customer_id');
        foreach ($orders as $customerOrders) {
            $c = $customerOrders->first()->customer;
            $ordered = $customerOrders->sum('total_amount');
            $paid    = $customerOrders->sum('deposit_paid');
            $sheet1[] = [
                $c->name ?? '—', $c->phone ?? '—', $c->type ?? '—',
                $customerOrders->count(), $ordered, $paid, $ordered - $paid,
            ];
        }

        // ── Sheet 2: Payment Log ──────────────────────────────────────
        $sheet2 = [
            ['Date', 'Customer', 'Phone', 'Order Number', 'Amount (Rp)', 'Method', 'Reference', 'Notes', 'Recorded By', 'Voided'],
        ];
        $payments = Payment::with(['order.customer', 'recordedBy'])
            ->whereHas('order', fn($q) => $q->where('trip_id', $tripId))
            ->orderBy('paid_at', 'desc')
            ->get();
        foreach ($payments as $p) {
            $sheet2[] = [
                $p->paid_at?->format('d/m/Y') ?? '—',
                $p->order->customer->name ?? '—',
                $p->order->customer->phone ?? '—',
                $p->order->order_number ?? '—',
                $p->amount,
                ucfirst($p->method ?? '—'),
                $p->reference ?? '—',
                $p->notes ?? '—',
                $p->recordedBy->name ?? '—',
                $p->isVoided() ? 'Yes' : 'No',
            ];
        }

        // ── Build two-sheet xlsx manually ─────────────────────────────
        $filename = "payments_{$label}.xlsx";
        $xlsx = $this->buildMultiSheetXlsx([
            'Outstanding Balances' => $sheet1,
            'Payment Log'          => $sheet2,
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_dl_');
        file_put_contents($tmp, $xlsx);
        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Build a multi-sheet xlsx binary from an associative array of
     * sheetName => rows[][].
     */
    /**
     * Build a multi-sheet xlsx binary. $sheets = ['Sheet Name' => $rows2d, ...]
     */
    private function buildMultiSheetXlsx(array $sheets): string
    {
        // Collect shared strings across all sheets
        $sharedStrings = [];
        $ssIndex = [];
        foreach ($sheets as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $cell) {
                    $s = (string) $cell;
                    if (!is_numeric($cell) && $s !== '' && !isset($ssIndex[$s])) {
                        $ssIndex[$s] = count($sharedStrings);
                        $sharedStrings[] = $s;
                    }
                }
            }
        }

        // Build each sheet XML
        $sheetXmls    = [];
        $sheetEntries = '';
        $relEntries   = '';
        $ctEntries    = '';
        $id = 1;

        foreach ($sheets as $name => $rows) {
            $sheetRows = '';
            foreach ($rows as $ri => $row) {
                $rowNum = $ri + 1;
                $cells  = '';
                foreach ($row as $ci => $cell) {
                    $col = $this->xlsxIndexToCol($ci);
                    $ref = $col . $rowNum;
                    $s   = (string) $cell;
                    if ($s === '') {
                        $cells .= '<c r="' . $ref . '"/>';
                    } elseif (is_numeric($cell)) {
                        $cells .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
                    } else {
                        $idx    = $ssIndex[$s];
                        $cells .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
                    }
                }
                $sheetRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
            }

            $sheetXmls['xl/worksheets/sheet' . $id . '.xml'] =
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';

            $safeName      = htmlspecialchars($name, ENT_XML1, 'UTF-8');
            $sheetEntries .= '<sheet name="' . $safeName . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $relEntries   .= '<Relationship Id="rId' . $id . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . $id . '.xml"/>';
            $ctEntries    .= '<Override PartName="/xl/worksheets/sheet' . $id . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $id++;
        }

        // Shared strings XML
        $ssEntries = '';
        foreach ($sharedStrings as $str) {
            $ssEntries .= '<si><t>' . htmlspecialchars($str, ENT_XML1, 'UTF-8') . '</t></si>';
        }
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">'
            . $ssEntries . '</sst>';

        $ssRelId = $id;

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetEntries . '</sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relEntries
            . '<Relationship Id="rId' . $ssRelId . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"'
            . ' Target="sharedStrings.xml"/>'
            . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $ctEntries
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        $dotRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',       $contentTypes);
        $zip->addFromString('_rels/.rels',               $dotRels);
        $zip->addFromString('xl/workbook.xml',           $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels',$workbookRels);
        $zip->addFromString('xl/sharedStrings.xml',      $ssXml);
        foreach ($sheetXmls as $path => $xml) {
            $zip->addFromString($path, $xml);
        }
        $zip->close();

        $content = file_get_contents($tmp);
        unlink($tmp);
        return $content;
    }


    public function verify(Request $request, Payment $payment)
    {
        if (!Auth::user()->hasPermission('payments.verify')) abort(403);
        if ($payment->isVoided()) return back()->with('error', 'Cannot verify a voided payment.');
        $payment->update([
            'verification_status' => 'verified',
            'verified_by'         => Auth::id(),
            'verified_at'         => now(),
            'dispute_note'        => null,
        ]);
        return back()->with('success', 'Payment of Rp ' . number_format($payment->amount, 0, ',', '.') . ' verified.');
    }

    /**
     * Verify an entire payment batch at once (all non-voided rows sharing the batch_id).
     * Useful when one customer transfer was split across several orders.
     */
    public function markPrinted(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'trip_id'     => 'required|exists:trips,id',
        ]);

        $orders = Order::where('customer_id', $data['customer_id'])
            ->where('trip_id', $data['trip_id'])->get();

        $allPrinted = $orders->isNotEmpty() && $orders->every(fn($o) => $o->invoice_printed_at !== null);

        \DB::transaction(function () use ($orders, $allPrinted) {
            foreach ($orders as $order) {
                $order->update([
                    'invoice_printed_at' => $allPrinted ? null : now(),
                    'invoice_printed_by' => $allPrinted ? null : Auth::id(),
                ]);
            }
        });

        return back()->with('success', $allPrinted ? 'Marked as NOT printed.' : 'Marked as printed — ready to pack.');
    }

    public function verifyBatch(Request $request, string $batchId)
    {
        if (!Auth::user()->hasPermission('payments.verify')) abort(403);

        $payments = Payment::where('batch_id', $batchId)
            ->whereNull('voided_at')
            ->where('verification_status', '!=', 'verified')
            ->get();

        if ($payments->isEmpty()) {
            return back()->with('error', 'No payments to verify in this batch.');
        }

        $total = 0;
        \DB::transaction(function () use ($payments, &$total) {
            foreach ($payments as $payment) {
                $payment->update([
                    'verification_status' => 'verified',
                    'verified_by'         => Auth::id(),
                    'verified_at'         => now(),
                    'dispute_note'        => null,
                ]);
                $total += $payment->amount;
            }
        });

        return back()->with('success', $payments->count() . ' payment(s) totaling Rp ' . number_format($total, 0, ',', '.') . ' verified.');
    }

    public function dispute(Request $request, Payment $payment)
    {
        if (!Auth::user()->hasPermission('payments.verify')) abort(403);
        if ($payment->isVoided()) return back()->with('error', 'Cannot dispute a voided payment.');
        $request->validate(['dispute_note' => 'required|string|max:1000']);
        $payment->update([
            'verification_status' => 'disputed',
            'verified_by'         => Auth::id(),
            'verified_at'         => now(),
            'dispute_note'        => $request->dispute_note,
        ]);
        return back()->with('success', 'Payment marked as disputed.');
    }

    private function recalcOrderPayment(?Order $order): void
    {
        if (!$order) return;
        $payments = $order->payments()->whereNull('voided_at')->get();
        $paid = $payments->where('type', '!=', 'refund')->sum('amount')
              - $payments->where('type', 'refund')->sum('amount');
        $status = $paid <= 0 ? 'unpaid'
            : ($paid >= $order->total_amount ? 'paid' : 'partial');
        if ($status === 'paid') {
            $order->items()->where('status', 'pending')->update(['status' => 'confirmed']);
        }
        $order->update(['deposit_paid' => max(0, $paid), 'payment_status' => $status]);
    }
}