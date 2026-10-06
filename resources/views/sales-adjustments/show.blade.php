@extends('layouts.app')
@section('title', $salesAdjustment->adjustment_number)
@section('page-title', $salesAdjustment->adjustment_number)

@php
    $order = $salesAdjustment->order;
    // "Original" = the order's state before this adjustment. Reconstructed
    // by adding the adjustment amount back onto the order's current
    // (post-adjustment) total and paid amount — correct as long as no
    // *other* adjustment landed on this order after this one, which is the
    // normal case; a note below covers the rarer stacked-adjustment case.
    $adjustedTotal = (float) $order->total_amount;
    // A Sales Return shrank the order's items, so the total was higher before
    // it. A Credit Note is a refund only — the total never changed, just paid.
    $originalTotal = $salesAdjustment->isReturn()
        ? $adjustedTotal + (float) $salesAdjustment->amount
        : $adjustedTotal;
    $adjustedPaid  = (float) $order->deposit_paid;
    $refundPaid    = $salesAdjustment->payment && !$salesAdjustment->payment->isVoided() ? (float) $salesAdjustment->payment->amount : 0;
    $originalPaid  = $adjustedPaid + $refundPaid;
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <span class="badge {{ $salesAdjustment->isReturn() ? 'bg-danger' : 'bg-warning text-dark' }} mb-1">
            {{ $salesAdjustment->isReturn() ? 'Sales Return' : 'Credit Note' }}
        </span>
        @if($salesAdjustment->isVoided())
            <span class="badge bg-secondary mb-1">VOIDED</span>
        @endif
        <div class="text-muted small">
            Order <a href="{{ route('orders.show', $order) }}" class="font-monospace">{{ $order->order_number }}</a>
            — {{ $order->customer->name }} — {{ $salesAdjustment->trip->name }}
        </div>
    </div>
    <a href="{{ route('sales-adjustments.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

@if($salesAdjustment->isVoided())
<div class="alert alert-secondary">
    Voided by {{ $salesAdjustment->voidedBy->name }} on {{ $salesAdjustment->voided_at->format('d M Y H:i') }}.
    @if($salesAdjustment->void_reason) Reason: {{ $salesAdjustment->void_reason }} @endif
</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Details</div>
            <div class="card-body small">
                <div class="row mb-1"><div class="col-5 text-muted">Number</div><div class="col-7 font-monospace">{{ $salesAdjustment->adjustment_number }}</div></div>
                <div class="row mb-1"><div class="col-5 text-muted">Issued</div><div class="col-7">{{ $salesAdjustment->created_at->format('d M Y H:i') }}</div></div>
                <div class="row mb-1"><div class="col-5 text-muted">Issued by</div><div class="col-7">{{ $salesAdjustment->createdBy->name }}</div></div>
                <div class="row mb-1"><div class="col-5 text-muted">Amount</div><div class="col-7 text-danger fw-semibold">- Rp {{ number_format($salesAdjustment->amount, 0, ',', '.') }}</div></div>
                <div class="row"><div class="col-5 text-muted">Reason</div><div class="col-7">{{ $salesAdjustment->reason ?? '—' }}</div></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">Original Invoice vs. Adjusted</div>
            <div class="card-body small">
                <table class="table table-sm mb-0">
                    <thead><tr><th></th><th class="text-end">Original</th><th class="text-end">Adjusted</th></tr></thead>
                    <tbody>
                        <tr><td>Total</td>
                            <td class="text-end">Rp {{ number_format($originalTotal, 0, ',', '.') }}</td>
                            <td class="text-end fw-semibold">Rp {{ number_format($adjustedTotal, 0, ',', '.') }}</td></tr>
                        <tr><td>Paid</td>
                            <td class="text-end">Rp {{ number_format($originalPaid, 0, ',', '.') }}</td>
                            <td class="text-end fw-semibold">Rp {{ number_format($adjustedPaid, 0, ',', '.') }}</td></tr>
                        <tr><td>Balance Due</td>
                            <td class="text-end">Rp {{ number_format(max(0, $originalTotal - $originalPaid), 0, ',', '.') }}</td>
                            <td class="text-end fw-semibold">Rp {{ number_format(max(0, $adjustedTotal - $adjustedPaid), 0, ',', '.') }}</td></tr>
                    </tbody>
                </table>
                <div class="text-muted mt-2" style="font-size:.75rem;">
                    <i class="bi bi-info-circle me-1"></i>"Original" is reconstructed by reversing this one adjustment — if this order has more than one Sales Return or Credit Note, this compares against the state immediately before <em>this specific</em> one, not necessarily the very first invoice.
                </div>
            </div>
        </div>
    </div>
</div>

@if($salesAdjustment->isReturn())
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold">Items Returned</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Product / Variant</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead>
            <tbody>
                @foreach($salesAdjustment->items as $line)
                <tr>
                    <td>{{ $line->product?->product_code ?? 'Product deleted' }} @if($line->variant) ({{ $line->variant->color }}/{{ $line->variant->size }}) @endif</td>
                    <td>{{ $line->quantity }}</td>
                    <td>Rp {{ number_format($line->unit_price, 0, ',', '.') }}</td>
                    <td>Rp {{ number_format($line->line_total, 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="d-flex gap-2">
    @if(!$salesAdjustment->isVoided())
    <div class="btn-group">
        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
            <i class="bi bi-receipt me-1"></i>View invoice
        </button>
        <ul class="dropdown-menu">
            <li><h6 class="dropdown-header">This order</h6></li>
            <li><a class="dropdown-item" target="_blank" href="{{ route('orders.invoice', [$order, 'view' => 'original']) }}">Before &mdash; original</a></li>
            <li><a class="dropdown-item" target="_blank" href="{{ route('orders.invoice', $order) }}">After &mdash; with returns &amp; credits</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><h6 class="dropdown-header">Customer's combined invoice</h6></li>
            <li><a class="dropdown-item" target="_blank" href="{{ route('orders.combined-invoice', ['customer' => $order->customer_id, 'trip_id' => $order->trip_id, 'view' => 'original']) }}">Before &mdash; original</a></li>
            <li><a class="dropdown-item" target="_blank" href="{{ route('orders.combined-invoice', ['customer' => $order->customer_id, 'trip_id' => $order->trip_id]) }}">After &mdash; with returns &amp; credits</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><span class="dropdown-item-text small text-muted" style="max-width:260px;white-space:normal;">"Before" reverses <strong>all</strong> active returns and credit notes on the order, not just this one.</span></li>
        </ul>
    </div>
    @else
    {{-- A voided document no longer affects the invoice, so there is no before/after to show. --}}
    <a href="{{ route('orders.invoice', $order) }}" class="btn btn-sm btn-outline-primary" target="_blank">
        <i class="bi bi-receipt me-1"></i>View Invoice
    </a>
    @endif
    @if(!$salesAdjustment->isVoided() && auth()->user()->hasPermission('orders.sales_adjustments'))
    <form method="POST" action="{{ route('sales-adjustments.void', $salesAdjustment) }}"
        onsubmit="return confirm('Void {{ $salesAdjustment->adjustment_number }}? This reverses its effect on the order.');">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-danger">Void This</button>
    </form>
    @endif
</div>
@endsection