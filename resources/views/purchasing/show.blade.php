@extends('layouts.app')
@section('title', $purchasing->po_number)
@section('page-title', 'Purchase Order: '.$purchasing->po_number)

@push('styles')
<style>
.sticky-po-bar {
    position: sticky;
    top: 0;
    z-index: 100;
    background: #fff;
    border-bottom: 2px solid #e5e7eb;
    padding: .6rem 1rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
    margin: -.5rem -1rem 1.25rem -1rem;
}
.sticky-po-bar .po-meta { font-size: .8rem; color: #6b7280; }
.sticky-po-bar .po-meta strong { color: #111; font-size: .9rem; }
.sticky-po-bar .divider { width: 1px; height: 28px; background: #e5e7eb; }
.arrival-row { transition: background .15s; }
.arrival-row:hover { background: #f0f9ff; }
.qty-received-input { width: 80px; }
.items-sticky-thead th {
    position: sticky;
    top: 56px; /* height of sticky bar */
    background: #f9fafb;
    z-index: 10;
}
</style>
@endpush

@section('content')

{{-- ── Sticky Action Bar ── --}}
<div class="sticky-po-bar">
    {{-- Back --}}
    <a href="{{ route('purchasing.index', ['trip_id' => $purchasing->trip_id]) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>

    {{-- Status badge --}}
    <span class="badge {{ match($purchasing->status) { 'arrived' => 'bg-success', 'confirmed' => 'bg-primary', 'submitted' => 'bg-warning text-dark', default => 'bg-secondary' } }}">
        {{ ucfirst($purchasing->status) }}
    </span>

    <div class="divider d-none d-md-block"></div>

    {{-- Key info --}}
    <div class="po-meta d-none d-md-flex gap-3">
        <div><strong>{{ $purchasing->po_number }}</strong></div>
        <div>Trip: <strong>{{ $purchasing->trip->name }}</strong></div>
        <div>Supplier: <strong>{{ $purchasing->supplier?->name ?? '—' }}</strong></div>
        <div>Total: <strong class="text-success">Rp {{ number_format($purchasing->total_amount, 0, ',', '.') }}</strong></div>
        <div>Items: <strong>{{ $purchasing->items->count() }} lines / {{ $purchasing->items->sum('quantity_ordered') }} pcs</strong></div>
    </div>

    <div class="ms-auto d-flex gap-2 align-items-center">
        {{-- Edit --}}
        @if($purchasing->status !== 'arrived')
        <a href="{{ route('purchasing.edit', $purchasing) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>Edit PO
        </a>
        @endif

        {{-- Delete --}}
        <form method="POST" action="{{ route('purchasing.destroy', $purchasing) }}"
            onsubmit="return confirm('{{ $purchasing->status === 'arrived' ? '⚠️ WARNING: This PO is already ARRIVED and stock has been allocated.\n\nDeleting it will NOT reverse allocated order items.\nAre you absolutely sure?' : 'Delete this purchase order? This cannot be undone.' }}')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash3 me-1"></i>Delete PO
            </button>
        </form>

        {{-- Confirm Arrival — primary CTA always visible --}}
        @if($purchasing->status !== 'arrived')
        <button type="button" class="btn btn-sm btn-success" onclick="submitArrival()">
            <i class="bi bi-check-circle me-1"></i>Confirm Arrival & Allocate
        </button>
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">

        {{-- PO Details --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><div class="text-muted small">PO Number</div><div class="font-monospace fw-bold">{{ $purchasing->po_number }}</div></div>
                    <div class="col-md-4"><div class="text-muted small">Trip</div><div>{{ $purchasing->trip->name }}</div></div>
                    <div class="col-md-4"><div class="text-muted small">Supplier</div><div>{{ $purchasing->supplier?->name ?? '—' }}</div></div>
                    <div class="col-md-4"><div class="text-muted small">Purchased Date</div><div>{{ $purchasing->purchased_at?->format('d M Y') ?? '—' }}</div></div>
                    <div class="col-md-4"><div class="text-muted small">Total</div><div class="fw-bold">Rp {{ number_format($purchasing->total_amount, 0, ',', '.') }}</div></div>
                </div>
            </div>
        </div>

        {{-- PO Items — with sticky thead and inline received qty if not arrived --}}
        <div class="card mb-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Items
                    <span class="badge bg-secondary ms-1">{{ $purchasing->items->count() }} lines</span>
                    <span class="badge bg-light text-dark border ms-1">{{ $purchasing->items->sum('quantity_ordered') }} pcs ordered</span>
                </span>
                @if($purchasing->status !== 'arrived')
                <span class="small text-muted"><i class="bi bi-info-circle me-1"></i>Enter received qty below then click <strong>Confirm Arrival</strong></span>
                @endif
            </div>
            <form method="POST" action="{{ route('purchasing.arrival', $purchasing) }}" id="arrivalForm">
                @csrf
                <div class="table-responsive">
                    <table class="table table-hover mb-0 small">
                        <thead class="table-light items-sticky-thead">
                            <tr>
                                <th>Product</th>
                                <th>Variant</th>
                                <th>Ordered</th>
                                @if($purchasing->status !== 'arrived')
                                <th>Received <span class="text-muted fw-normal">(edit)</span></th>
                                @else
                                <th>Received</th>
                                @endif
                                <th>Unit Cost</th>
                                <th>Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($purchasing->items as $i => $item)
                            <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
                            <tr class="arrival-row">
                                <td class="fw-semibold">{{ $item->product->name }}</td>
                                <td>{{ $item->variant?->label ?? '—' }}</td>
                                <td>{{ $item->quantity_ordered }}</td>
                                <td>
                                    @if($purchasing->status !== 'arrived')
                                    <input type="number"
                                        name="items[{{ $i }}][quantity_received]"
                                        class="form-control form-control-sm qty-received-input"
                                        value="{{ $item->quantity_received ?: $item->quantity_ordered }}"
                                        min="0" max="{{ $item->quantity_ordered }}">
                                    @else
                                    {{ $item->quantity_received }}
                                    @endif
                                </td>
                                <td>Rp {{ number_format($item->unit_cost, 0, ',', '.') }}</td>
                                <td>Rp {{ number_format($item->line_total, 0, ',', '.') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($purchasing->status !== 'arrived')
                {{-- Bottom submit button for convenience when scrolled to bottom --}}
                <div class="card-footer bg-white d-flex justify-content-between align-items-center py-3">
                    <span class="small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Adjust received quantities above, then confirm. Orders not covered will be marked <strong>Sold Out</strong>.
                    </span>
                    <button type="button" class="btn btn-success" onclick="submitArrival()">
                        <i class="bi bi-check-circle me-1"></i>Confirm Arrival & Allocate
                    </button>
                </div>
                @endif
            </form>
        </div>

        @if($purchasing->status === 'arrived')
        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>Stock has been received and allocated via FIFO.</div>
        @endif

    </div>
</div>

@push('scripts')
<script>
function submitArrival() {
    if (confirm('Confirm arrival and run FIFO allocation?\n\nThis will update all order item statuses based on received quantities.')) {
        document.getElementById('arrivalForm').submit();
    }
}
</script>
@endpush

@endsection