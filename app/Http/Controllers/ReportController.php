<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $trips    = Trip::orderByDesc('id')->get();
        $tripId   = $request->trip_id;

        // Summary stats
        $query = Order::query();
        if ($tripId) $query->where('trip_id', $tripId);

        $summary = [
            'total_orders'    => $query->count(),
            'total_revenue'   => (clone $query)->sum('total_amount'),
            'total_paid'      => (clone $query)->sum('deposit_paid'),
            'total_unpaid'    => (clone $query)->sum(DB::raw('total_amount - deposit_paid')),
            'paid_orders'     => (clone $query)->where('payment_status', 'paid')->count(),
            'partial_orders'  => (clone $query)->where('payment_status', 'partial')->count(),
            'unpaid_orders'   => (clone $query)->where('payment_status', 'unpaid')->count(),
        ];

        // Top customers by order value
        $topCustomers = Customer::withSum(['orders as total_spent' => function ($q) use ($tripId) {
                if ($tripId) $q->where('trip_id', $tripId);
            }], 'total_amount')
            ->withCount(['orders as order_count' => function ($q) use ($tripId) {
                if ($tripId) $q->where('trip_id', $tripId);
            }])
            ->orderByDesc('total_spent')
            ->limit(10)
            ->get();

        // Top products by qty sold
        $topProducts = Product::withSum(['orderItems as total_qty' => function ($q) use ($tripId) {
                $q->whereNotIn('status', ['cancelled', 'sold_out']);
                if ($tripId) $q->whereHas('order', fn($o) => $o->where('trip_id', $tripId));
            }], 'quantity')
            ->withSum(['orderItems as total_revenue' => function ($q) use ($tripId) {
                $q->whereNotIn('status', ['cancelled', 'sold_out']);
                if ($tripId) $q->whereHas('order', fn($o) => $o->where('trip_id', $tripId));
            }], 'line_total')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();

        // Sales by trip
        $salesByTrip = Trip::withSum('orders as total_revenue', 'total_amount')
            ->withSum('orders as total_paid', 'deposit_paid')
            ->withCount('orders')
            ->orderByDesc('id')
            ->get();

        $selectedTrip = $tripId ? Trip::find($tripId) : null;

        return view('reports.index', compact(
            'trips', 'summary', 'topCustomers', 'topProducts', 'salesByTrip', 'selectedTrip'
        ));
    }

    // ── Exports ──────────────────────────────────────────────────────

    /** Export all orders as CSV */
    public function exportOrders(Request $request)
    {
        $query = Order::with('customer', 'trip', 'shippingArea', 'createdBy');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $orders = $query->latest()->get();

        return $this->streamCsv('orders_export.csv', function ($out) use ($orders) {
            fputcsv($out, [
                'order_number','customer_name','customer_type','customer_phone',
                'trip','shipping_area','subtotal','discount','shipping_fee',
                'shipping_discount','total_amount','paid','balance_due',
                'payment_status','created_at','notes'
            ]);
            foreach ($orders as $o) {
                fputcsv($out, [
                    $o->order_number,
                    $o->customer->name,
                    $o->customer->type,
                    $o->customer->phone,
                    $o->trip->name,
                    $o->shippingArea?->name ?? '',
                    $o->subtotal,
                    $o->discount_amount,
                    $o->shipping_fee,
                    $o->shipping_discount,
                    $o->total_amount,
                    $o->deposit_paid,
                    $o->total_amount - $o->deposit_paid,
                    $o->payment_status,
                    $o->created_at->format('Y-m-d H:i'),
                    $o->notes,
                ]);
            }
        });
    }

    /** Export all order items (detail level) */
    public function exportOrderItems(Request $request)
    {
        $query = OrderItem::with('order.customer', 'order.trip', 'product', 'variant');
        if ($request->trip_id) {
            $query->whereHas('order', fn($q) => $q->where('trip_id', $request->trip_id));
        }
        $items = $query->latest()->get();

        return $this->streamCsv('order_items_export.csv', function ($out) use ($items) {
            fputcsv($out, [
                'order_number','customer_name','customer_type','trip',
                'product_name','product_code','variant','quantity',
                'unit_price','line_total','status','notes'
            ]);
            foreach ($items as $i) {
                fputcsv($out, [
                    $i->order->order_number,
                    $i->order->customer->name,
                    $i->order->customer->type,
                    $i->order->trip->name,
                    $i->product->name,
                    $i->product->product_code ?? '',
                    $i->variant?->label ?? '',
                    $i->quantity,
                    $i->unit_price,
                    $i->line_total,
                    $i->status,
                    $i->notes,
                ]);
            }
        });
    }

    /** Export customers */
    public function exportCustomers()
    {
        $customers = Customer::withCount('orders')
            ->withSum('orders as total_spent', 'total_amount')
            ->orderBy('name')
            ->get();

        return $this->streamCsv('customers_export.csv', function ($out) use ($customers) {
            fputcsv($out, ['name','phone','type','address','notes','total_orders','total_spent']);
            foreach ($customers as $c) {
                fputcsv($out, [
                    $c->name, $c->phone, $c->type, $c->address, $c->notes,
                    $c->orders_count, $c->total_spent ?? 0,
                ]);
            }
        });
    }

    /** Export products */
    public function exportProducts(Request $request)
    {
        $query = Product::with('trip')
            ->withSum(['orderItems as total_ordered' => fn($q) =>
                $q->whereNotIn('status', ['cancelled','sold_out'])
            ], 'quantity');
        if ($request->trip_id) $query->where('trip_id', $request->trip_id);
        $products = $query->orderBy('name')->get();

        return $this->streamCsv('products_export.csv', function ($out) use ($products) {
            fputcsv($out, [
                'trip','name','product_code','sku','brand','price','weight_gram',
                'excluded_from_promo','status','total_ordered'
            ]);
            foreach ($products as $p) {
                fputcsv($out, [
                    $p->trip->name, $p->name, $p->product_code ?? '', $p->sku ?? '',
                    $p->brand ?? '', $p->price, $p->weight_gram,
                    $p->excluded_from_promo ? 'yes' : 'no',
                    $p->status, $p->total_ordered ?? 0,
                ]);
            }
        });
    }

    /** Import customers from CSV */
    public function importCustomers(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        $handle   = fopen($request->file('file')->getRealPath(), 'r');
        $header   = fgetcsv($handle);
        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(trim($row[0] ?? ''))) continue;
            Customer::firstOrCreate(
                ['name' => trim($row[0])],
                [
                    'phone'   => trim($row[1] ?? ''),
                    'type'    => in_array(trim($row[2] ?? ''), ['customer','reseller','selected_customer'])
                                    ? trim($row[2]) : 'customer',
                    'address' => trim($row[3] ?? ''),
                    'notes'   => trim($row[4] ?? ''),
                ]
            );
            $imported++;
        }
        fclose($handle);

        return redirect()->route('reports.index')->with('success', "Imported {$imported} customers.");
    }

    private function streamCsv(string $filename, callable $callback)
    {
        return Response::stream(function () use ($callback) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility
            fputs($out, "\xEF\xBB\xBF");
            $callback($out);
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
