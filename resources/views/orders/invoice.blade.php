@php
    $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
    $storeTagline = \App\Models\Setting::get('store_tagline', '');
    $storePhone   = \App\Models\Setting::get('store_phone', '');
    $storeAddress = \App\Models\Setting::get('store_address', '');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice {{ $order->order_number }}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size:13px; color:#1a1a1a; background:#fff; }

.page { max-width:750px; margin:0 auto; padding:36px 40px; }

/* Header */
.invoice-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; padding-bottom:20px; border-bottom:2px solid #1e2a3a; }
.brand-name { font-size:22px; font-weight:800; color:#1e2a3a; letter-spacing:-.5px; }
.brand-sub  { font-size:11px; color:#64748b; margin-top:3px; }
.invoice-meta { text-align:right; }
.invoice-meta .inv-num { font-size:18px; font-weight:700; color:#1e2a3a; }
.invoice-meta .inv-date { font-size:11px; color:#64748b; margin-top:4px; }
.invoice-meta .status-badge { display:inline-block; margin-top:6px; padding:3px 10px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.badge-paid    { background:#dcfce7; color:#166534; }
.badge-partial { background:#fef9c3; color:#713f12; }
.badge-unpaid  { background:#fee2e2; color:#991b1b; }

/* Customer & Trip info */
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
.info-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; }
.info-box h4 { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin-bottom:8px; }
.info-box p  { font-size:13px; color:#1e293b; line-height:1.55; }
.info-box .name { font-weight:700; font-size:14px; }

/* Items table */
.section-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin-bottom:10px; }
table { width:100%; border-collapse:collapse; margin-bottom:20px; }
table thead th { background:#1e2a3a; color:#fff; padding:9px 12px; text-align:left; font-size:11px; font-weight:600; }
table thead th:last-child, table thead th.right { text-align:right; }
table tbody td { padding:9px 12px; border-bottom:1px solid #f1f5f9; font-size:12.5px; vertical-align:middle; }
table tbody tr:last-child td { border-bottom:none; }
table tbody tr:hover td { background:#f8fafc; }
td.right { text-align:right; }
.item-name { font-weight:600; }
.item-meta { font-size:11px; color:#64748b; margin-top:2px; }
.status-pill { display:inline-block; padding:1px 7px; border-radius:10px; font-size:10px; font-weight:600; }
.s-pending   { background:#fef9c3; color:#854d0e; }
.s-confirmed { background:#dbeafe; color:#1e40af; }
.s-arrived   { background:#dcfce7; color:#166534; }
.s-sold_out  { background:#fee2e2; color:#991b1b; }
.s-cancelled { background:#f1f5f9; color:#64748b; }
.s-purchased { background:#ede9fe; color:#5b21b6; }

/* Totals */
.totals-wrap { display:flex; justify-content:flex-end; margin-bottom:24px; }
.totals-table { width:280px; }
.totals-table tr td { padding:5px 0; font-size:13px; }
.totals-table tr td:last-child { text-align:right; font-weight:600; }
.totals-table .total-row td { border-top:2px solid #1e2a3a; padding-top:10px; font-size:16px; font-weight:800; color:#1e2a3a; }
.totals-table .discount-row td { color:#16a34a; }
.totals-table .balance-row td { color:{{ $order->remaining_balance > 0 ? '#dc2626' : '#16a34a' }}; }

/* Payments */
.payment-section { margin-bottom:24px; }
.payment-row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #e2e8f0; font-size:12px; }
.payment-row:last-child { border-bottom:none; }

/* Notes */
.notes-box { background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:10px 14px; margin-bottom:20px; font-size:12px; }
.notes-box span { font-weight:600; color:#92400e; }

/* Footer */
.invoice-footer { border-top:1px solid #e2e8f0; padding-top:16px; text-align:center; font-size:11px; color:#94a3b8; }

/* Print */
@media print {
    body { background:#fff; }
    .no-print { display:none !important; }
    .page { padding:20px; }
}
</style>
</head>
<body>

{{-- Print / Back toolbar --}}
<div class="no-print" style="background:#1e2a3a;padding:10px 20px;display:flex;align-items:center;gap:12px;">
    <a href="{{ route('orders.show', $order) }}" style="color:#94a3b8;text-decoration:none;font-size:13px;">
        ← Back to Order
    </a>
    <button onclick="window.print()" style="margin-left:auto;background:#3b82f6;color:#fff;border:none;padding:7px 20px;border-radius:6px;font-size:13px;cursor:pointer;font-weight:600;">
        🖨 Print Invoice
    </button>
</div>

<div class="page">

    {{-- Header --}}
    <div class="invoice-header">
        <div>
            <div class="brand-name">{{ $storeName }}</div>
            @if($storeTagline)<div class="brand-sub">{{ $storeTagline }}</div>@endif
            @if($storePhone)<div class="brand-sub">📱 {{ $storePhone }}</div>@endif
            @if($storeAddress)<div class="brand-sub">{{ $storeAddress }}</div>@endif
        </div>
        <div class="invoice-meta">
            <div class="inv-num">{{ $order->order_number }}</div>
            <div class="inv-date">Issued: {{ $order->created_at->format('d M Y') }}</div>
            <div class="inv-date">Trip: <strong>{{ $order->trip->name }}</strong></div>
            @php
                $badgeClass = match($order->payment_status) {
                    'paid'    => 'badge-paid',
                    'partial' => 'badge-partial',
                    default   => 'badge-unpaid',
                };
                $badgeLabel = match($order->payment_status) {
                    'paid'    => 'Fully Paid',
                    'partial' => 'Partially Paid',
                    default   => 'Unpaid',
                };
            @endphp
            <div><span class="status-badge {{ $badgeClass }}">{{ $badgeLabel }}</span></div>
        </div>
    </div>

    {{-- Customer + Shipping --}}
    <div class="info-grid">
        <div class="info-box">
            <h4>Bill To</h4>
            <p class="name">{{ $order->customer->name }}</p>
            @if($order->customer->phone)
                <p>📱 {{ $order->customer->phone }}</p>
            @endif
            <p style="margin-top:4px;">
                <span style="background:#e0e7ff;color:#3730a3;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:600;">
                    {{ $order->customer->type_label }}
                </span>
            </p>
            @if($order->customer->address)
                <p style="margin-top:6px;font-size:12px;color:#64748b;">{{ $order->customer->address }}</p>
            @endif
        </div>
        <div class="info-box">
            <h4>Delivery Info</h4>
            @if($order->shippingArea)
                <p class="name">{{ $order->shippingArea->name }}</p>
                @if($order->shippingArea->province)
                    <p style="color:#64748b;">{{ $order->shippingArea->province }}</p>
                @endif
                <p style="margin-top:6px;font-size:12px;">
                    Weight: <strong>{{ $order->shipping_kg_charged }} kg</strong>
                </p>
                <p style="font-size:12px;">
                    Rate: Rp {{ number_format($order->shippingArea->price_per_kg, 0, ',', '.') }}/kg
                </p>
            @else
                <p style="color:#94a3b8;">No shipping area set</p>
            @endif
            @if($order->notes)
                <p style="margin-top:6px;font-size:11px;color:#92400e;background:#fffbeb;padding:4px 8px;border-radius:4px;">
                    📝 {{ $order->notes }}
                </p>
            @endif
        </div>
    </div>

    {{-- Items --}}
    <div class="section-title">Order Items</div>
    <table>
        <thead>
            <tr>
                <th style="width:40%;">Product</th>
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
            <tr {{ $soldOut ? 'style=opacity:.55' : '' }}>
                <td>
                    <div class="item-name">{{ $item->product->name }}</div>
                    @if($item->product->product_code)
                        <div class="item-meta">Code: {{ $item->product->product_code }}</div>
                    @endif
                </td>
                <td>{{ $item->variant?->label ?? '—' }}</td>
                <td class="right">{{ $item->quantity }}</td>
                <td class="right">{{ $soldOut ? 'Rp 0' : 'Rp '.number_format($item->unit_price, 0, ',', '.') }}</td>
                <td class="right">{{ $soldOut ? 'Rp 0' : 'Rp '.number_format($item->line_total, 0, ',', '.') }}</td>
                <td>
                    <span class="status-pill s-{{ $item->status }}">
                        {{ ucfirst(str_replace('_', ' ', $item->status)) }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <div class="totals-wrap">
        <table class="totals-table">
            <tr>
                <td style="color:#64748b;">Subtotal</td>
                <td>Rp {{ number_format($order->subtotal, 0, ',', '.') }}</td>
            </tr>
            @if($order->discount_amount > 0)
            <tr class="discount-row">
                <td>Promo Discount</td>
                <td>− Rp {{ number_format($order->discount_amount, 0, ',', '.') }}</td>
            </tr>
            @endif
            <tr>
                <td style="color:#64748b;">Shipping Fee</td>
                <td>Rp {{ number_format($order->shipping_fee, 0, ',', '.') }}</td>
            </tr>
            @if($order->shipping_discount > 0)
            <tr class="discount-row">
                <td>Shipping Discount</td>
                <td>− Rp {{ number_format($order->shipping_discount, 0, ',', '.') }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>Total</td>
                <td>Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td style="color:#16a34a;">Paid</td>
                <td style="color:#16a34a;">Rp {{ number_format($order->deposit_paid, 0, ',', '.') }}</td>
            </tr>
            <tr class="balance-row">
                <td>Balance Due</td>
                <td>Rp {{ number_format($order->remaining_balance, 0, ',', '.') }}</td>
            </tr>
        </table>
    </div>

    {{-- Payment history --}}
    @if($order->payments->count())
    <div class="payment-section">
        <div class="section-title">Payment History</div>
        @foreach($order->payments as $payment)
        <div class="payment-row">
            <span>
                {{ $payment->paid_at->format('d M Y') }} —
                <strong>{{ ucfirst($payment->type) }}</strong>
                @if($payment->method) · {{ $payment->method }} @endif
                @if($payment->reference) · Ref: {{ $payment->reference }} @endif
            </span>
            <span style="{{ $payment->type === 'refund' ? 'color:#dc2626' : 'color:#16a34a' }}; font-weight:600;">
                {{ $payment->type === 'refund' ? '−' : '+' }}Rp {{ number_format($payment->amount, 0, ',', '.') }}
            </span>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Footer --}}
    <div class="invoice-footer">
        <p>Generated {{ now()->format('d M Y H:i') }} · {{ $storeName }}</p>
    </div>

</div>
</body>
</html>
