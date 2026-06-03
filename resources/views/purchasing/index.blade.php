@extends('layouts.app')
@section('title', 'Purchasing')
@section('page-title', 'Purchasing')

@section('content')
{{-- Trip selector --}}
<form class="d-flex gap-2 mb-3 align-items-center">
    <select name="trip_id" class="form-select form-select-sm" style="width:300px;" onchange="this.form.submit()">
        <option value="">Select trip…</option>
        @foreach($trips as $trip)
            <option value="{{ $trip->id }}" {{ $selectedTrip?->id == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
        @endforeach
    </select>
</form>

@if($selectedTrip)

<div class="row g-3 mb-4">
    {{-- Demand Summary --}}
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold d-flex justify-content-between align-items-center">
                <span>Customer Demand — {{ $selectedTrip->name }}</span>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newPOModal">
                    <i class="bi bi-file-earmark-plus me-1"></i>Create Purchase Order
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr><th>Product</th><th>Variant</th><th>Demanded</th><th>Supplier Stock</th><th>Gap</th></tr>
                    </thead>
                    <tbody>
                        @forelse($demandData as $row)
                        <tr>
                            <td class="fw-semibold">{{ $row['product']->name }}</td>
                            <td>{{ $row['variant']?->label ?? 'Default' }}</td>
                            <td>{{ $row['total_demanded'] }}</td>
                            <td>{{ $row['supplier_stock'] }}</td>
                            <td>
                                @php $gap = $row['supplier_stock'] - $row['total_demanded']; @endphp
                                <span class="{{ $gap < 0 ? 'text-danger fw-bold' : 'text-success' }}">
                                    {{ $gap >= 0 ? '+' : '' }}{{ $gap }}
                                </span>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No active order items for this trip</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Purchase Orders list --}}
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">Purchase Orders</div>
            <ul class="list-group list-group-flush">
                @forelse($purchaseOrders as $po)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="font-monospace small fw-semibold">{{ $po->po_number }}</div>
                        <div class="text-muted" style="font-size:.75rem;">{{ $po->supplier_name ?? 'No supplier' }} · {{ $po->items->count() }} items</div>
                        <div class="text-muted" style="font-size:.75rem;">Rp {{ number_format($po->total_amount, 0, ',', '.') }}</div>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-1">
                        <span class="badge {{ match($po->status) { 'arrived' => 'bg-success', 'confirmed' => 'bg-primary', 'submitted' => 'bg-warning text-dark', default => 'bg-secondary' } }}">
                            {{ ucfirst($po->status) }}
                        </span>
                        <a href="{{ route('purchasing.show', $po) }}" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2">View</a>
                    </div>
                </li>
                @empty
                <li class="list-group-item text-center text-muted small py-3">No purchase orders yet</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

{{-- Create PO Modal --}}
<div class="modal fade" id="newPOModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('purchasing.store') }}">
                @csrf
                <input type="hidden" name="trip_id" value="{{ $selectedTrip->id }}">
                <div class="modal-header">
                    <h5 class="modal-title">New Purchase Order — {{ $selectedTrip->name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Supplier Name</label>
                        <input type="text" name="supplier_name" class="form-control" placeholder="e.g. Shein Wholesale">
                    </div>
                    <div class="fw-semibold mb-2">Items to Purchase</div>
                    @foreach($demandData as $i => $row)
                    <div class="row g-2 align-items-center mb-2">
                        <input type="hidden" name="items[{{ $i }}][product_id]" value="{{ $row['product']->id }}">
                        <input type="hidden" name="items[{{ $i }}][product_variant_id]" value="{{ $row['variant']?->id }}">
                        <div class="col-md-4 small">
                            <strong>{{ $row['product']->name }}</strong>
                            @if($row['variant']) <br><span class="text-muted">{{ $row['variant']->label }}</span> @endif
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Qty to Order</label>
                            <input type="number" name="items[{{ $i }}][quantity_ordered]" class="form-control form-control-sm" value="{{ $row['total_demanded'] }}" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Unit Cost (Rp)</label>
                            <input type="number" name="items[{{ $i }}][unit_cost]" class="form-control form-control-sm" value="{{ $row['product']->price }}" step="1000">
                        </div>
                        <div class="col-md-2 small text-muted">
                            Demand: {{ $row['total_demanded'] }}
                        </div>
                    </div>
                    @endforeach
                    @if(empty($demandData))
                        <p class="text-muted text-center py-3">No demand data. Add orders first.</p>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" {{ empty($demandData) ? 'disabled' : '' }}>Create PO</button>
                </div>
            </form>
        </div>
    </div>
</div>

@else
<div class="card p-5 text-center text-muted">
    <i class="bi bi-box-seam fs-1 mb-3 d-block"></i>
    Select a trip above to view purchasing demand and create purchase orders.
</div>
@endif
@endsection
