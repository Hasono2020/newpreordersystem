@php
    $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
    $storeTagline = \App\Models\Setting::get('store_tagline', '');
    $storePhone   = \App\Models\Setting::get('store_phone', '');

    $grandSubtotal     = $orders->sum('subtotal');
    $grandPaid         = $orders->sum('deposit_paid');
    $trip              = $orders->first()->trip;
    $grandDiscount     = $combinedDiscount;
    $grandShipping     = $combinedShipping;
    $grandShipDiscount = $combinedShipDiscount;
    $grandTotal        = max(0, $grandSubtotal - $grandDiscount + $grandShipping - $grandShipDiscount);
    $grandBalance      = $grandTotal - $grandPaid;
    // Sum of per-order shipping (what was charged separately) vs combined shipping — shows the saving
    $sumPerOrderShipping = $orders->sum('shipping_fee');
    $shippingSaving      = max(0, $sumPerOrderShipping - $grandShipping);
    $allItems          = $orders->flatMap(fn($o) => $o->items->map(fn($i) => ['item' => $i, 'order' => $o]));
    // Voided payments are already excluded from deposit_paid (recalculated
    // when a payment is voided), so exclude them here too — otherwise a
    // voided payment would still show up as a real line item on the invoice.
    $allPayments       = $orders->flatMap(fn($o) => $o->payments)->reject(fn($p) => $p->isVoided())->sortBy('paid_at');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Invoice &mdash; {{ $customer->name }}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Arial,sans-serif; font-size:11px; color:#1a1a1a; background:#fff; }
.page { max-width:780px; margin:0 auto; padding:24px 28px; }
.inv-header { display:flex; justify-content:space-between; align-items:flex-start; padding-bottom:10px; border-bottom:2px solid #1e2a3a; margin-bottom:12px; }
.brand { font-size:17px; font-weight:800; color:#1e2a3a; }
.brand-sub { font-size:10px; color:#64748b; line-height:1.5; }
.inv-title { text-align:right; }
.inv-title .t { font-size:14px; font-weight:700; color:#1e2a3a; }
.inv-title .s { font-size:10px; color:#64748b; line-height:1.6; }
.badge { display:inline-block; background:#dbeafe; color:#1d4ed8; padding:2px 8px; border-radius:10px; font-size:9px; font-weight:700; }
.info-row { display:flex; gap:12px; margin-bottom:12px; }
.info-box { flex:1; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; }
.info-box h5 { font-size:8px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin-bottom:5px; }
.info-box .nm { font-weight:800; font-size:18px; }
.info-box .sm { font-size:10px; color:#475569; line-height:1.5; }
.type-pill { display:inline-block; padding:1px 7px; border-radius:8px; font-size:9px; font-weight:700; }
.sec-title { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin-bottom:5px; }
/* ── Compact grouped table ── */
table { width:100%; border-collapse:collapse; }
table thead th { background:#1e2a3a; color:#fff; padding:4px 7px; text-align:left; font-size:9.5px; font-weight:600; }
table thead th.r { text-align:right; }
table tbody td { padding:2.5px 7px; border-bottom:1px solid #f1f5f9; font-size:9.5px; vertical-align:middle; line-height:1.3; }
table tbody tr:last-child td { border-bottom:none; }
td.r { text-align:right; }
.p-name { font-weight:600; font-size:9.5px; }
.p-meta { font-size:8.5px; color:#64748b; }
/* Product group header row */
.grp-hdr td { background:#f8fafc; font-weight:700; font-size:9.5px; color:#1e2a3a; padding:3px 7px; border-top:1px solid #e2e8f0; border-bottom:none; }
/* Two-column layout for items (print space saving) */
.items-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 12px; }
.items-grid table { font-size:9px; }
@media print {
    .items-grid { grid-template-columns:1fr 1fr; }
}
.s-pill { display:inline-block; padding:1px 5px; border-radius:6px; font-size:9px; font-weight:600; }
.s-pending   { background:#fef9c3; color:#854d0e; }
.s-confirmed { background:#dbeafe; color:#1e40af; }
.s-purchased { background:#ede9fe; color:#5b21b6; }
.s-arrived   { background:#dcfce7; color:#166534; }
.s-sold_out  { background:#fee2e2; color:#991b1b; }
.s-cancelled { background:#f1f5f9; color:#64748b; }
.pay-s { display:inline-block; padding:1px 6px; border-radius:6px; font-size:9px; font-weight:600; }
.bottom { display:flex; gap:16px; margin-top:14px; align-items:flex-start; }
.grand-box { border:2px solid #1e2a3a; border-radius:8px; padding:12px 16px; min-width:260px; }
.grand-box h4 { font-size:10px; font-weight:700; color:#1e2a3a; margin-bottom:8px; padding-bottom:6px; border-bottom:1px solid #e2e8f0; }
.g-row { display:flex; justify-content:space-between; margin-bottom:4px; font-size:11px; }
.g-row .lbl { color:#475569; }
.g-row.total { font-weight:800; font-size:13px; border-top:2px solid #1e2a3a; padding-top:6px; margin-top:4px; }
.g-row.bal { font-weight:700; font-size:12px; color:#dc2626; }
.disc { color:#16a34a; }
.promo-note { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:5px; padding:5px 8px; font-size:9px; color:#166534; margin-bottom:8px; }
.pay-box { flex:1; }
.pay-box h4 { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin-bottom:6px; }
.pay-row { display:flex; justify-content:space-between; padding:3px 0; border-bottom:1px solid #f1f5f9; font-size:10px; }
@page { size: A5; margin: 8mm; }
@media print {
    .no-print { display:none !important; }
    .page { padding:0; }
    body { font-size:10px; }
    table tbody td { padding:3px 7px; }
}
</style>
</head>
<body>
<div class="page">

<div class="no-print" style="margin-bottom:14px;display:flex;gap:8px;">
    <button onclick="window.print()" style="padding:7px 18px;background:#1e2a3a;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:12px;">&#128424; Print / Save PDF</button>
    <button onclick="window.history.length > 1 ? window.history.back() : window.location.href='/customers'" style="padding:7px 18px;background:#f1f5f9;color:#1e2a3a;border:1px solid #e2e8f0;border-radius:5px;cursor:pointer;font-size:12px;">&#8592; Back</button>
    <span style="font-size:10px;color:#94a3b8;align-self:center;">Tip: in the print dialog, choose <strong>"Save as PDF"</strong> as the destination to save. Paper size is set to A5.</span>
</div>

{{-- Header --}}
<div class="inv-header">
    <div>
        <div class="brand">{{ $storeName }}</div>
        @if($storeTagline)
            <div class="brand-sub">{{ $storeTagline }}</div>
        @endif
        @if($storePhone)
            <div class="brand-sub">&#128222; {{ $storePhone }}</div>
        @endif
    </div>
    <div class="inv-title">
        <div class="t">COMBINED INVOICE</div>
        <div class="s">{{ $orders->count() }} Orders &middot; {{ $trip->name }}<br>Printed: {{ now()->format('d M Y H:i') }}</div>
        <span class="badge">{{ $orders->count() }} orders merged</span>
    </div>
</div>

{{-- Customer + Delivery + Stats --}}
<div class="info-row">
    <div class="info-box">
        <h5>Bill To</h5>
        <div class="nm">{{ $customer->name }}</div>
        <div class="sm">
            &#128222; {{ $customer->phone }}
            &nbsp;
            @if($customer->type === 'reseller')
                <span class="type-pill" style="background:#ede9fe;color:#5b21b6;">Reseller</span>
            @elseif($customer->type === 'selected_customer')
                <span class="type-pill" style="background:#dbeafe;color:#1e40af;">Selected</span>
            @else
                <span class="type-pill" style="background:#f1f5f9;color:#475569;">Customer</span>
            @endif
        </div>
        @if($customer->address)
            <div class="sm" style="margin-top:2px;">{{ $customer->address }}</div>
        @endif
    </div>
    <div class="info-box">
        <h5>Delivery Info</h5>
        @if($shippingArea)
            <div class="nm">
                {{ $shippingArea->name }}
                @if($shippingArea->province), {{ $shippingArea->province }}@endif
            </div>
            <div class="sm">Weight: <strong>{{ number_format($totalWeightGram) }}g</strong> ({{ $chargeableKg }} kg) &middot; Rate: @if($shippingArea->isFlatFee())Flat Rp {{ number_format($shippingArea->flat_fee, 0, ',', '.') }}@else Rp {{ number_format($shippingArea->price_per_kg, 0, ',', '.') }}/kg@endif</div>
        @else
            <div class="sm" style="color:#94a3b8;">No shipping area set</div>
        @endif
    </div>
    <div class="info-box" style="flex:0.6;">
        <h5>Summary</h5>
        <div class="sm">
            Orders: <strong>{{ $orders->count() }}</strong><br>
            Items: <strong>{{ $allItems->count() }}</strong><br>
            Paid: <strong>{{ $orders->where('payment_status','paid')->count() }}</strong>
            &middot; Partial: <strong>{{ $orders->where('payment_status','partial')->count() }}</strong>
            &middot; Unpaid: <strong>{{ $orders->where('payment_status','unpaid')->count() }}</strong>
        </div>
    </div>
</div>

@php
    // Group items by product for compact display
    $groupedItems = $allItems->groupBy(fn($row) => $row['item']->product_id);
    $activeCount  = $allItems->filter(fn($row) => !in_array($row['item']->status, ['sold_out','cancelled']))->count();
    $soldOutCount = $allItems->filter(fn($row) => $row['item']->status === 'sold_out')->count();
@endphp

{{-- All items — grouped by product, compact 2-col layout --}}
<div class="sec-title" style="margin-bottom:4px;">
    All Items &mdash; {{ $allItems->count() }} items across {{ $orders->count() }} orders
    @if($soldOutCount > 0)
        <span style="color:#dc2626;margin-left:8px;">{{ $soldOutCount }} sold out</span>
    @endif
</div>

<div class="items-grid">
@php $colItems = $groupedItems->chunk((int)ceil($groupedItems->count()/2)); @endphp
@foreach($colItems as $colChunk)
<table>
    <thead>
        <tr>
            <th>Product / Variant</th>
            <th class="r" style="width:28px;">Qty</th>
            <th class="r" style="width:72px;">Price</th>
            <th class="r" style="width:72px;">Total</th>
            <th style="width:48px;">Status</th>
        </tr>
    </thead>
    <tbody>
    @foreach($colChunk as $productId => $rows)
    @php
        $firstRow    = $rows->first();
        $productCode = $firstRow['item']->product->product_code;
        $groupQty    = $rows->sum(fn($r) => $r['item']->quantity);
    @endphp
    {{-- Product group header — no price shown here; it's redundant with
         (and can be confusing next to) the per-variant rows below it. --}}
    <tr class="grp-hdr">
        <td colspan="5">
            {{ $productCode ?? '—' }}
        </td>
    </tr>
    {{-- Variant rows --}}
    @foreach($rows as $row)
    @php $item = $row['item']; $so = in_array($item->status, ['sold_out', 'cancelled']); @endphp
    <tr style="{{ $so ? 'opacity:.45;' : '' }}">
        <td style="padding-left:12px;color:#475569;">{{ $item->variant?->label ?? '—' }}</td>
        <td class="r">{{ $item->quantity }}</td>
        <td class="r">{{ $so ? 'Rp 0' : 'Rp '.number_format($item->unit_price,0,',','.') }}</td>
        <td class="r" style="font-weight:600;">{{ $so ? 'Rp 0' : 'Rp '.number_format($item->line_total,0,',','.') }}</td>
        <td><span class="s-pill s-{{ $item->status }}" style="font-size:8px;">{{ ucfirst(str_replace('_',' ',$item->status)) }}</span></td>
    </tr>
    @endforeach
    @endforeach
    </tbody>
</table>
@endforeach
</div>

{{-- Bottom: Grand Total + Payments --}}
<div class="bottom">

    {{-- Grand Total --}}
    <div class="grand-box">
        <h4 style="display:flex; justify-content:space-between; align-items:center;">
            <span>Grand Total &mdash; {{ $orders->count() }} Orders</span>
            <span style="font-weight:700; color:#1e2a3a;">Total Qty: {{ $allActiveItems->sum('quantity') }} items</span>
        </h4>
        @if($combinedPromo)
            <div class="promo-note">
                &#10022; <strong>{{ $combinedPromo['rule']->name }}</strong> applied
                ({{ $allActiveItems->sum('quantity') }} items combined)
                @if($combinedShipDiscount >= $combinedShipping && $combinedShipping > 0)
                    &mdash; <strong>FREE Shipping</strong>
                @endif
            </div>
        @endif
        <div class="g-row"><span class="lbl">Subtotal</span><span>Rp {{ number_format($grandSubtotal, 0, ',', '.') }}</span></div>
        @if($grandDiscount > 0)
            <div class="g-row"><span class="lbl">Discount</span><span class="disc">&ndash; Rp {{ number_format($grandDiscount, 0, ',', '.') }}</span></div>
        @endif
        <div class="g-row"><span class="lbl">Shipping (combined {{ $chargeableKg }}kg)</span><span>Rp {{ number_format($grandShipping, 0, ',', '.') }}</span></div>
        @if($shippingSaving > 0)
            <div class="g-row" style="font-size:10px;color:#16a34a;">
                <span class="lbl">Combined shipping saving</span>
                <span>&ndash; Rp {{ number_format($shippingSaving, 0, ',', '.') }}</span>
            </div>
        @endif
        @if($grandShipDiscount > 0)
            <div class="g-row"><span class="lbl">Ship. Discount</span><span class="disc">&ndash; Rp {{ number_format($grandShipDiscount, 0, ',', '.') }}</span></div>
        @endif
        <div class="g-row total"><span>Grand Total</span><span>Rp {{ number_format($grandTotal, 0, ',', '.') }}</span></div>
        <div class="g-row" style="margin-top:6px;font-size:11px;">
            <span class="lbl">Total Paid</span>
            <span style="color:#16a34a;font-weight:600;">Rp {{ number_format($grandPaid, 0, ',', '.') }}</span>
        </div>
        <div class="g-row bal"><span>Balance Due</span><span>Rp {{ number_format($grandBalance, 0, ',', '.') }}</span></div>
    </div>

    {{-- Payment History --}}
    @if($allPayments->count() > 0)
        <div class="pay-box">
            <h4>Payment History</h4>
            @foreach($allPayments as $pay)
                <div class="pay-row">
                    <span>{{ \Carbon\Carbon::parse($pay->paid_at)->format('d M Y') }} &mdash; {{ ucfirst($pay->type) }}</span>
                    <span style="color:#16a34a;font-weight:600;">+ Rp {{ number_format($pay->amount, 0, ',', '.') }}</span>
                </div>
            @endforeach
        </div>
    @endif

</div>

</div>
<script>
    // Auto-open the print/save dialog when ?autoprint=1 is in the URL
    if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    }
</script>
</body>
</html>