@php
    $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
    $storeTagline = \App\Models\Setting::get('store_tagline', '');
    $storePhone   = \App\Models\Setting::get('store_phone', '');
    $storeAddress = \App\Models\Setting::get('store_address', '');

    // Aggregate totals across all orders
    $grandSubtotal        = $orders->sum('subtotal');
    $grandDiscount        = $orders->sum('discount_amount');
    $grandShipping        = $orders->sum('shipping_fee');
    $grandShipDiscount    = $orders->sum('shipping_discount');
    $grandTotal           = $orders->sum('total_amount');
    $grandPaid            = $orders->sum('deposit_paid');
    $grandBalance         = $grandTotal - $grandPaid;
    $trip                 = $orders->first()->trip;
    $shippingArea         = $orders->first()->shippingArea;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Combined Invoice — {{ $customer->name }}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Arial,sans-serif; font-size:13px; color:#1a1a1a; background:#fff; }
.page { max-width:800px; margin:0 auto; padding:36px 40px; }

.invoice-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; padding-bottom:20px; border-bottom:2px solid #1e2a3a; }
.brand-name { font-size:22px; font-weight:800; color:#1e2a3a; }
.brand-sub  { font-size:11px; color:#64748b; margin-top:3px; }
.invoice-meta { text-align:right; }
.invoice-meta .inv-num { font-size:16px; font-weight:700; color:#1e2a3a; }
.invoice-meta .inv-date { font-size:11px; color:#64748b; margin-top:4px; }
.combined-badge { display:inline-block; background:#dbeafe; color:#1d4ed8; padding:3px 10px; border-radius:20px; font-size:10px; font-weight:700; margin-top:6px; }

.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
.info-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; }
.info-box h4 { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin-bottom:8px; }
.info-box .name { font-weight:700; font-size:14px; }
.info-box p { font-size:13px; color:#1e293b; line-height:1.55; }

/* Order group */
.order-group { margin-bottom:28px; }
.order-group-header { background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; padding:8px 14px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; }
.order-group-header .ord-num { font-weight:700; font-size:12px; color:#1e2a3a; }
.order-group-header .ord-time { font-size:11px; color:#64748b; }

.section-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin-bottom:10px; }
table { width:100%; border-collapse:collapse; margin-bottom:8px; }
table thead th { background:#1e2a3a; color:#fff; padding:8px 12px; text-align:left; font-size:11px; font-weight:600; }
table thead th.right { text-align:right; }
table tbody td { padding:8px 12px; border-bottom:1px solid #f1f5f9; font-size:12px; vertical-align:middle; }
table tbody tr:last-child td { border-bottom:none; }
td.right { text-align:right; }
.item-name { font-weight:600; }
.item-meta { font-size:11px; color:#64748b; margin-top:2px; }
.status-pill { display:inline-block; padding:1px 7px; border-radius:10px; font-size:10px; font-weight:600; }
.s-pending   { background:#fef9c3; color:#854d0e; }
.s-confirmed { background:#dbeafe; color:#1e40af; }
.s-purchased { background:#ede9fe; color:#5b21b6; }
.s-arrived   { background:#dcfce7; color:#166534; }
.s-sold_out  { background:#fee2e2; color:#991b1b; }
.s-cancelled { background:#f1f5f9; color:#64748b; }

/* Grand summary */
.grand-summary { border:2px solid #1e2a3a; border-radius:10px; padding:20px 24px; margin-top:24px; }
.grand-summary h3 { font-size:13px; font-weight:700; color:#1e2a3a; margin-bottom:14px; padding-bottom:8px; border-bottom:1px solid #e2e8f0; }
.summary-row { display:flex; justify-content:space-between; margin-bottom:7px; font-size:13px; }
.summary-row.total { font-weight:800; font-size:16px; border-top:2px solid #1e2a3a; padding-top:10px; margin-top:6px; }
.summary-row.balance { font-weight:700; font-size:14px; color:#dc2626; }
.summary-row .label { color:#475569; }
.summary-row .amount { font-weight:600; }
.discount { color:#16a34a; }

/* Orders summary table at top */
.orders-summary { margin-bottom:24px; }
.orders-summary table thead th { background:#374151; }

/* Payments */
.payments { margin-top:12px; }
.pay-row { display:flex; justify-content:space-between; font-size:12px; padding:4px 0; border-bottom:1px solid #f1f5f9; }

@media print {
    .no-print { display:none !important; }
    .page { padding:20px; }
    body { font-size:12px; }
}
</style>
</head>
<body>
<div class="page">

    {{-- Print / Back buttons --}}
    <div class="no-print" style="margin-bottom:20px;display:flex;gap:10px;">
        <button onclick="window.print()" style="padding:8px 20px;background:#1e2a3a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;">
            🖨 Print / Save PDF
        </button>
        <button onclick="window.history.back()" style="padding:8px 20px;background:#f1f5f9;color:#1e2a3a;border:1px solid #e2e8f0;border-radius:6px;cursor:pointer;font-size:13px;">
            ← Back
        </button>
    </div>

    {{-- Header --}}
    <div class="invoice-header">
        <div>
            <div class="brand-name">{{ $storeName }}</div>
            @if($storeTagline)<div class="brand-sub">{{ $storeTagline }}</div>@endif
            @if($storePhone)<div class="brand-sub">📞 {{ $storePhone }}</div>@endif
        </div>
        <div class="invoice-meta">
            <div class="inv-num">COMBINED INVOICE</div>
            <div class="inv-date">{{ $orders->count() }} Orders · Trip: {{ $trip->name }}</div>
            <div class="inv-date">Printed: {{ now()->format('d M Y H:i') }}</div>
            <span class="combined-badge">{{ $orders->count() }} orders merged</span>
        </div>
    </div>

    {{-- Customer & Delivery --}}
    <div class="info-grid">
        <div class="info-box">
            <h4>Bill To</h4>
            <div class="name">{{ $customer->name }}</div>
            <p>📱 {{ $customer->phone }}</p>
            <p>
                @if($customer->type === 'reseller') <span style="background:#ede9fe;color:#5b21b6;padding:1px 8px;border-radius:10px;font-size:10px;font-weight:700;">Reseller</span>
                @elseif($customer->type === 'selected_customer') <span style="background:#dbeafe;color:#1e40af;padding:1px 8px;border-radius:10px;font-size:10px;font-weight:700;">Selected Customer</span>
                @else <span style="background:#f1f5f9;color:#475569;padding:1px 8px;border-radius:10px;font-size:10px;font-weight:700;">Customer</span>
                @endif
            </p>
            @if($customer->address)<p style="margin-top:4px;">{{ $customer->address }}</p>@endif
        </div>
        <div class="info-box">
            <h4>Delivery Info</h4>
            @if($shippingArea)
                <div class="name">{{ $shippingArea->name }}</div>
                <p>{{ $shippingArea->province ?? '' }}</p>
                <p style="margin-top:6px;font-size:12px;">
                    Total Weight: <strong>{{ number_format($orders->sum('shipping_weight_gram')) }}g</strong><br>
                    Rate: Rp {{ number_format($shippingArea->price_per_kg, 0, ',', '.') }}/kg
                </p>
            @else
                <p style="color:#94a3b8;">No shipping area set</p>
            @endif
        </div>
    </div>

    {{-- Orders summary --}}
    <div class="orders-summary">
        <div class="section-title">Orders Summary</div>
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date & Time</th>
                    <th class="right">Subtotal</th>
                    <th class="right">Discount</th>
                    <th class="right">Total</th>
                    <th>Payment</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orders as $order)
                <tr>
                    <td style="font-family:monospace;font-size:11px;">{{ $order->order_number }}</td>
                    <td style="font-size:11px;color:#64748b;">{{ $order->created_at->format('d M Y H:i') }}</td>
                    <td class="right">Rp {{ number_format($order->subtotal, 0, ',', '.') }}</td>
                    <td class="right" style="color:#16a34a;">{{ $order->discount_amount > 0 ? '- Rp '.number_format($order->discount_amount, 0, ',', '.') : '—' }}</td>
                    <td class="right" style="font-weight:600;">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td>
                    <td>
                        @if($order->payment_status === 'paid') <span class="status-pill" style="background:#dcfce7;color:#166534;">Paid</span>
                        @elseif($order->payment_status === 'partial') <span class="status-pill" style="background:#fef9c3;color:#854d0e;">Partial</span>
                        @else <span class="status-pill" style="background:#fee2e2;color:#991b1b;">Unpaid</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Items per order --}}
    @foreach($orders as $order)
    <div class="order-group">
        <div class="order-group-header">
            <span class="ord-num">{{ $order->order_number }}</span>
            <span class="ord-time">Ordered: {{ $order->created_at->format('d M Y, H:i') }}</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:35%">Product</th>
                    <th>Variant</th>
                    <th class="right">Qty</th>
                    <th class="right">Unit Price</th>
                    <th class="right">Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $item)
                @php $soldOut = $item->status === 'sold_out'; @endphp
                <tr style="{{ $soldOut ? 'opacity:.55' : '' }}">
                    <td>
                        <div class="item-name">{{ $item->product->name }}</div>
                        @if($item->product->product_code)
                            <div class="item-meta">{{ $item->product->product_code }}</div>
                        @endif
                    </td>
                    <td>{{ $item->variant?->label ?? '—' }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ $soldOut ? 'Rp 0' : 'Rp '.number_format($item->unit_price, 0, ',', '.') }}</td>
                    <td class="right">{{ $soldOut ? 'Rp 0' : 'Rp '.number_format($item->line_total, 0, ',', '.') }}</td>
                    <td><span class="status-pill s-{{ $item->status }}">{{ ucfirst(str_replace('_', ' ', $item->status)) }}</span></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endforeach

    {{-- Grand total summary --}}
    <div class="grand-summary">
        <h3>Grand Total — All {{ $orders->count() }} Orders</h3>
        <div class="summary-row">
            <span class="label">Subtotal</span>
            <span class="amount">Rp {{ number_format($grandSubtotal, 0, ',', '.') }}</span>
        </div>
        @if($grandDiscount > 0)
        <div class="summary-row">
            <span class="label">Promo Discount</span>
            <span class="discount">– Rp {{ number_format($grandDiscount, 0, ',', '.') }}</span>
        </div>
        @endif
        <div class="summary-row">
            <span class="label">Shipping Fee</span>
            <span class="amount">Rp {{ number_format($grandShipping, 0, ',', '.') }}</span>
        </div>
        @if($grandShipDiscount > 0)
        <div class="summary-row">
            <span class="label">Shipping Discount</span>
            <span class="discount">– Rp {{ number_format($grandShipDiscount, 0, ',', '.') }}</span>
        </div>
        @endif
        <div class="summary-row total">
            <span>Grand Total</span>
            <span>Rp {{ number_format($grandTotal, 0, ',', '.') }}</span>
        </div>
        <div class="summary-row" style="margin-top:8px;">
            <span class="label">Total Paid</span>
            <span class="amount" style="color:#16a34a;">Rp {{ number_format($grandPaid, 0, ',', '.') }}</span>
        </div>
        <div class="summary-row balance">
            <span>Balance Due</span>
            <span>Rp {{ number_format($grandBalance, 0, ',', '.') }}</span>
        </div>
    </div>

    {{-- All payments --}}
    @php $allPayments = $orders->flatMap(fn($o) => $o->payments)->sortBy('paid_at'); @endphp
    @if($allPayments->count() > 0)
    <div class="payments" style="margin-top:20px;">
        <div class="section-title">Payment History</div>
        @foreach($allPayments as $pay)
        <div class="pay-row">
            <span>{{ \Carbon\Carbon::parse($pay->paid_at)->format('d M Y') }} — {{ ucfirst($pay->type) }} ({{ $pay->method ?? 'transfer' }})</span>
            <span style="color:#16a34a;font-weight:600;">+ Rp {{ number_format($pay->amount, 0, ',', '.') }}</span>
        </div>
        @endforeach
    </div>
    @endif

</div>
</body>
</html>
