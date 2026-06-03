@extends('layouts.app')
@section('title', $purchasing->po_number)
@section('page-title', 'Purchase Order: '.$purchasing->po_number)

@section('content')
<div class="d-flex gap-2 mb-3">
    <a href="{{ route('purchasing.index', ['trip_id' => $purchasing->trip_id]) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    <span class="badge align-self-center {{ match($purchasing->status) { 'arrived' => 'bg-success', 'confirmed' => 'bg-primary', 'submitted' => 'bg-warning text-dark', default => 'bg-secondary' } }}">
        {{ ucfirst($purchasing->status) }}
    </span>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><div class="text-muted small">PO Number</div><div class="font-monospace fw-bold">{{ $purchasing->po_number }}</div></div>
                    <div class="col-md-4"><div class="text-muted small">Trip</div><div>{{ $purchasing->trip->name }}</div></div>
                    <div class="col-md-4"><div class="text-muted small">Supplier</div><div>{{ $purchasing->supplier_name ?? '—' }}</div></div>
                    <div class="col-md-4"><div class="text-muted small">Purchased Date</div><div>{{ $purchasing->purchased_at?->format('d M Y') ?? '—' }}</div></div>
                    <div class="col-md-4"><div class="text-muted small">Total</div><div class="fw-bold">Rp {{ number_format($purchasing->total_amount, 0, ',', '.') }}</div></div>
                </div>
            </div>
        </div>

        {{-- PO Items --}}
        <div class="card mb-3">
            <div class="card-header bg-white py-3 fw-semibold">Items</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr><th>Product</th><th>Variant</th><th>Ordered</th><th>Received</th><th>Unit Cost</th><th>Line Total</th></tr>
                    </thead>
                    <tbody>
                        @foreach($purchasing->items as $item)
                        <tr>
                            <td class="fw-semibold">{{ $item->product->name }}</td>
                            <td>{{ $item->variant?->label ?? '—' }}</td>
                            <td>{{ $item->quantity_ordered }}</td>
                            <td>{{ $item->quantity_received }}</td>
                            <td>Rp {{ number_format($item->unit_cost, 0, ',', '.') }}</td>
                            <td>Rp {{ number_format($item->line_total, 0, ',', '.') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Confirm arrival form --}}
        @if($purchasing->status !== 'arrived')
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">Confirm Arrival & Allocate Stock (FIFO)</div>
            <div class="card-body">
                <p class="small text-muted">Enter actual quantities received. Stock will be allocated to customer orders using first-in-first-out. Customers who don't receive stock will be marked as <strong>Sold Out</strong>.</p>
                <form method="POST" action="{{ route('purchasing.arrival', $purchasing) }}">
                    @csrf
                    @foreach($purchasing->items as $i => $item)
                    <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
                    <div class="row g-2 align-items-center mb-2">
                        <div class="col-md-5 small">
                            <strong>{{ $item->product->name }}</strong>
                            @if($item->variant) · {{ $item->variant->label }} @endif
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Ordered: {{ $item->quantity_ordered }}</label>
                            <input type="number" name="items[{{ $i }}][quantity_received]" class="form-control form-control-sm"
                                value="{{ $item->quantity_ordered }}" min="0" max="{{ $item->quantity_ordered }}">
                        </div>
                    </div>
                    @endforeach
                    <div class="mt-3">
                        <button type="submit" class="btn btn-success" onclick="return confirm('Confirm arrival and run FIFO allocation? This will update all order item statuses.')">
                            <i class="bi bi-check-circle me-1"></i>Confirm Arrival & Allocate
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @else
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>Stock has been received and allocated via FIFO.</div>
        @endif
    </div>
</div>
@endsection
