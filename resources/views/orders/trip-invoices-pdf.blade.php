{{--
    Whole-trip invoices PDF — one COMBINED invoice per customer, each starting on
    its own A5 page.

    Built for dompdf, which cannot do flexbox or CSS grid (the on-screen invoice
    uses both), so the layout here is tables throughout. It does no calculating:
    every figure arrives ready-made from CombinedInvoiceService::build() as plain
    arrays — the same service the on-screen invoice takes its promo/shipping from.
    Item amounts are shown without the "Rp" prefix (the column headers say Rp) so
    the two-column item list fits A5 in a wide Unicode font.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Invoices &mdash; {{ $tripName }}</title>
<style>
@page { size: A5 portrait; margin: 9mm 8mm; }
/* Not a universal `* { margin:0 }` reset: in dompdf that also overrides the @page
   margin above, pushing everything to the very edge of the paper. */
body, div, h5 { margin: 0; padding: 0; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 7pt; color: #1a1a1a; line-height: 1.35; }
table { border-collapse: collapse; width: 100%; }
td, th { vertical-align: top; text-align: left; }
.r { text-align: right; }
.muted { color: #64748b; }
.grn { color: #16a34a; }
.red { color: #dc2626; }

.head td { padding-bottom: 5pt; border-bottom: 1.5pt solid #1e2a3a; }
.brand { font-size: 12.5pt; font-weight: bold; color: #1e2a3a; }
.title { font-size: 10pt; font-weight: bold; color: #1e2a3a; text-align: right; }
.sub { font-size: 6.4pt; color: #64748b; }

.box { border: 0.6pt solid #e2e8f0; background: #f8fafc; padding: 4pt 6pt; }
.box h5 { font-size: 5.6pt; font-weight: bold; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.5pt; margin-bottom: 2pt; }
.nm { font-size: 10.5pt; font-weight: bold; color: #111; }
.small { font-size: 6.6pt; color: #475569; }

.sec { font-size: 6pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5pt; color: #64748b; margin: 8pt 0 3pt; }

.items th { background: #1e2a3a; color: #fff; font-size: 6.2pt; padding: 2pt 3pt; white-space: nowrap; }
.items td { font-size: 6.5pt; padding: 1.1pt 3pt; line-height: 1.2; border-bottom: 0.4pt solid #f1f5f9; }
.items .grp td { background: #f8fafc; font-weight: bold; color: #1e2a3a; border-top: 0.4pt solid #e2e8f0; }
.items .dim td { color: #94a3b8; }

.grand { border: 1.4pt solid #1e2a3a; padding: 5pt 7pt; }
.grand .hd td { font-size: 7pt; font-weight: bold; color: #1e2a3a; padding-bottom: 3pt; border-bottom: 0.6pt solid #e2e8f0; }
.grand td { font-size: 7pt; padding: 1.2pt 0; }
.grand .lbl { color: #475569; }
.grand .total td { font-size: 9pt; font-weight: bold; border-top: 1.4pt solid #1e2a3a; padding-top: 3pt; }
.promo { background: #f0fdf4; border: 0.6pt solid #bbf7d0; color: #166534; font-size: 6.2pt; padding: 2.5pt 4pt; margin: 3pt 0 4pt; }
.pay td { font-size: 6.8pt; padding: 2pt 0; border-bottom: 0.4pt solid #f1f5f9; }
</style>
</head>
<body>
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); $num = fn ($n) => number_format((float) $n, 0, ',', '.'); @endphp
@foreach($invoices as $i => $inv)
@php
    $c    = $inv['customer'];
    $t    = $inv['totals'];
    $d    = $inv['delivery'];
@endphp
<div style="{{ $i > 0 ? 'page-break-before: always;' : '' }}">

    {{-- Header --}}
    <table class="head" cellspacing="0" cellpadding="0">
        <tr>
            <td style="width:55%;">
                <div class="brand">{{ $storeName }}</div>
                @if($storeTagline)<div class="sub">{{ $storeTagline }}</div>@endif
                @if($storePhone)<div class="sub">Tel: {{ $storePhone }}</div>@endif
            </td>
            <td style="width:45%;">
                <div class="title">COMBINED INVOICE</div>
                <div class="sub r">{{ $inv['order_count'] }} Orders &middot; {{ $inv['trip_name'] }}</div>
                <div class="sub r">Printed: {{ $printedAt }}</div>
            </td>
        </tr>
    </table>

    {{-- Bill to / Delivery / Summary --}}
    <table cellspacing="0" cellpadding="0" style="margin-top:7pt;">
        <tr>
            <td class="box" style="width:37%;">
                <h5>Bill To</h5>
                <div class="nm">{{ $c['name'] }}</div>
                <div class="small">
                    Tel: {{ $c['phone'] }} &nbsp;<b>{{ $c['type'] }}</b>@if($c['cargo']) &nbsp;<b>Cargo</b>@endif
                </div>
                @if($c['address'])<div class="small" style="margin-top:1.5pt;">{{ $c['address'] }}</div>@endif
            </td>
            <td style="width:2%;"></td>
            <td class="box" style="width:37%;">
                <h5>Delivery Info</h5>
                @if($d)
                    <div class="nm" style="font-size:9pt;">{{ $d['name'] }}</div>
                    <div class="small">
                        Weight: <b>{{ $num($d['weight_g']) }}g</b> (<b>{{ $d['kg'] }} kg</b>)
                        @if($c['cargo']) <span>(includes cargo +1kg)</span>@endif
                        &middot; Rate: {{ $d['rate'] }}
                    </div>
                @else
                    <div class="small muted">No shipping area set</div>
                @endif
            </td>
            <td style="width:2%;"></td>
            <td class="box" style="width:22%;">
                <h5>Summary</h5>
                <div class="small">
                    Orders: <b>{{ $inv['order_count'] }}</b><br>
                    Items: <b>{{ $inv['item_count'] }}</b><br>
                    Paid: <b>{{ $inv['paid_count'] }}</b> &middot; Partial: <b>{{ $inv['partial_count'] }}</b><br>
                    Unpaid: <b>{{ $inv['unpaid_count'] }}</b>
                </div>
            </td>
        </tr>
    </table>

    {{-- Items, grouped by product. Laid out as page-sized chunks (see
         CombinedInvoiceService::paginateGroups) because dompdf can't break a
         two-column row across pages — each chunk is its own, shorter, row. --}}
    <div class="sec">
        All Items &mdash; {{ $inv['item_count'] }} items across {{ $inv['order_count'] }} orders
        @if($inv['sold_out_count'] > 0)<span class="red"> &nbsp;{{ $inv['sold_out_count'] }} sold out</span>@endif
    </div>
    @foreach($inv['pages'] as $chunk)
    <table cellspacing="0" cellpadding="0" style="margin-bottom:3pt;">
        <tr>
        @foreach([$chunk['left'], $chunk['right']] as $ci => $col)
            <td style="width:49%;">
            @if(count($col) > 0)
                <table class="items" cellspacing="0" cellpadding="0">
                    <thead>
                        <tr>
                            <th>Product / Variant</th>
                            <th class="r" style="width:14pt;">Qty</th>
                            <th class="r" style="width:38pt;">Price (Rp)</th>
                            <th class="r" style="width:42pt;">Total (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($col as $g)
                        <tr class="grp"><td colspan="4">{{ $g['code'] }}</td></tr>
                        @foreach($g['rows'] as $row)
                        <tr class="{{ $row['dim'] ? 'dim' : '' }}">
                            <td style="padding-left:8pt;">{{ $row['label'] }}</td>
                            <td class="r">{{ $row['qty'] }}</td>
                            <td class="r">{{ $num($row['unit_price']) }}</td>
                            <td class="r"><b>{{ $num($row['line_total']) }}</b></td>
                        </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            @endif
            </td>
            @if($ci === 0)<td style="width:2%;"></td>@endif
        @endforeach
        </tr>
    </table>
    @endforeach

    {{-- Grand total + payment history --}}
    <table cellspacing="0" cellpadding="0" style="margin-top:9pt; page-break-inside: avoid;">
        <tr>
            <td style="width:56%;">
                <div class="grand">
                    <table cellspacing="0" cellpadding="0">
                        <tr class="hd">
                            <td>Grand Total &mdash; {{ $inv['order_count'] }} Orders</td>
                            <td class="r">Total Qty: {{ $t['total_qty'] }}</td>
                        </tr>
                    </table>
                    @if($inv['promo'])
                        <div class="promo">
                            <b>{{ $inv['promo']['name'] }}</b> applied ({{ $t['total_qty'] }} items combined)
                            @if($inv['promo']['free_shipping']) &mdash; <b>FREE Shipping</b>@endif
                        </div>
                    @endif
                    <table cellspacing="0" cellpadding="0" style="margin-top:2pt;">
                        <tr><td class="lbl">Subtotal</td><td class="r">{{ $rp($t['subtotal']) }}</td></tr>
                        @if($t['discount'] > 0)
                        <tr><td class="lbl">Discount</td><td class="r grn">- {{ $rp($t['discount']) }}</td></tr>
                        @endif
                        <tr><td class="lbl">Shipping (combined {{ $t['kg'] }}kg)</td><td class="r">{{ $rp($t['shipping']) }}</td></tr>
                        @if($t['ship_discount'] > 0)
                        <tr><td class="lbl">Ship. Discount</td><td class="r grn">- {{ $rp($t['ship_discount']) }}</td></tr>
                        @endif
                    </table>
                    <table cellspacing="0" cellpadding="0" style="margin-top:3pt;">
                        <tr class="total"><td>Grand Total</td><td class="r">{{ $rp($t['grand_total']) }}</td></tr>
                    </table>
                    <table cellspacing="0" cellpadding="0" style="margin-top:3pt;">
                        <tr><td class="lbl">Total Paid</td><td class="r grn"><b>{{ $rp($t['paid']) }}</b></td></tr>
                        <tr><td class="red"><b>Balance Due</b></td><td class="r red"><b>{{ $rp($t['balance']) }}</b></td></tr>
                    </table>
                </div>
            </td>
            <td style="width:3%;"></td>
            <td style="width:41%;">
                @if(count($inv['payments']) > 0)
                    <div class="sec" style="margin-top:0;">Payment History</div>
                    <table class="pay" cellspacing="0" cellpadding="0">
                        @foreach($inv['payments'] as $pay)
                        <tr>
                            <td>{{ $pay['date'] }} &mdash; {{ $pay['label'] }}</td>
                            <td class="r {{ $pay['refund'] ? 'red' : 'grn' }}"><b>{{ $pay['refund'] ? '-' : '+' }} {{ $rp($pay['amount']) }}</b></td>
                        </tr>
                        @endforeach
                    </table>
                @endif
            </td>
        </tr>
    </table>

</div>
@endforeach
</body>
</html>
