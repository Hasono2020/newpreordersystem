@extends('layouts.app')
@section('title', 'Purchasing')
@section('page-title', 'Purchasing')

@section('content')
<form class="d-flex gap-2 mb-3 align-items-center">
    <select name="trip_id" class="form-select form-select-sm" style="width:300px;" onchange="this.form.submit()">
        <option value="">Select trip…</option>
        @foreach($trips as $trip)
            <option value="{{ $trip->id }}" {{ $selectedTrip?->id == $trip->id ? 'selected' : '' }}>
                {{ $trip->name }}
            </option>
        @endforeach
    </select>
</form>

@if($selectedTrip)

<div class="alert alert-light border small mb-3 py-2">
    <i class="bi bi-info-circle text-primary me-1"></i>
    <strong>Multiple suppliers:</strong> Demand is grouped by supplier below.
    Create one <strong>Purchase Order per supplier</strong>. Each PO is confirmed independently when stock arrives.
</div>

<div class="row g-3 mb-4">
    {{-- Demand grouped by supplier --}}
    <div class="col-lg-7">
        @forelse($demandBySupplier as $supplierId => $group)
        @php
            $supplierName   = $group['supplier_name'];
            $supplierRealId = $group['supplier_id'];
            $rows           = $group['rows'];
        @endphp
        <div class="card mb-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-semibold">{{ $supplierName }}</span>
                    <span class="badge bg-light text-secondary border ms-2">{{ count($rows) }} product(s)</span>
                </div>
                <button class="btn btn-sm btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#newPOModal"
                    data-supplier-id="{{ $supplierRealId }}"
                    data-supplier-name="{{ $supplierName }}">
                    <i class="bi bi-file-earmark-plus me-1"></i>Create PO
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr><th>Product</th><th>Variant</th><th>Demanded</th><th>Stock</th><th>Gap</th></tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $row['product']->name }}</div>
                                @if($row['product']->product_code)
                                    <span class="text-muted font-monospace" style="font-size:.72rem;">{{ $row['product']->product_code }}</span>
                                @endif
                            </td>
                            <td>{{ $row['variant']?->label ?? 'Default' }}</td>
                            <td><span class="badge bg-primary">{{ $row['total_demanded'] }}</span></td>
                            <td>{{ $row['supplier_stock'] }}</td>
                            <td>
                                @php $gap = $row['supplier_stock'] - $row['total_demanded']; @endphp
                                <span class="{{ $gap < 0 ? 'text-danger fw-bold' : 'text-success' }}">
                                    {{ $gap >= 0 ? '+' : '' }}{{ $gap }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @empty
        <div class="card p-4 text-center text-muted">
            @if($purchaseOrders->count() > 0)
                <i class="bi bi-check2-circle fs-1 mb-2 d-block text-success"></i>
                <div class="fw-semibold">All suppliers have Purchase Orders for this trip.</div>
                <div class="mt-1 small">View and manage them in the Purchase Orders list →</div>
            @else
                <i class="bi bi-inbox fs-1 mb-2 d-block"></i>
                No active order items for this trip yet.
            @endif
        </div>
        @endforelse
    </div>

    {{-- Purchase Orders list --}}
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">
                Purchase Orders
                <span class="badge bg-secondary ms-1">{{ $purchaseOrders->count() }}</span>
            </div>
            <ul class="list-group list-group-flush">
                @forelse($purchaseOrders as $po)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="font-monospace small fw-semibold">{{ $po->po_number }}</div>
                        <div class="text-muted" style="font-size:.75rem;">
                            {{ $po->supplier?->name ?? 'No supplier' }} · {{ $po->items->count() }} item(s)
                        </div>
                        <div class="text-muted" style="font-size:.75rem;">
                            Rp {{ number_format($po->total_amount, 0, ',', '.') }}
                        </div>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-1">
                        <span class="badge {{ match($po->status) {
                            'arrived'   => 'bg-success',
                            'confirmed' => 'bg-primary',
                            'submitted' => 'bg-warning text-dark',
                            default     => 'bg-secondary'
                        } }}">{{ ucfirst($po->status) }}</span>
                        <a href="{{ route('purchasing.show', $po) }}" class="btn btn-sm btn-outline-secondary py-0 px-2">View</a>
                    </div>
                </li>
                @empty
                <li class="list-group-item text-center text-muted small py-3">No purchase orders yet</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

{{-- Create PO Modal — items filtered by selected supplier --}}
<div class="modal fade" id="newPOModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('purchasing.store') }}" id="poForm">
                @csrf
                <input type="hidden" name="trip_id" value="{{ $selectedTrip->id }}">
                <input type="hidden" name="supplier_id" id="poSupplierId">

                <div class="modal-header">
                    <h5 class="modal-title" id="poModalTitle">New Purchase Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3" id="poSupplierInfo" style="display:none;">
                        <i class="bi bi-building me-1"></i>
                        Supplier: <strong id="poSupplierNameDisplay"></strong>
                        <span class="text-muted ms-2">— only products from this supplier are shown below</span>
                    </div>

                    <div class="fw-semibold small text-muted mb-2">Items to Purchase</div>
                    <div id="poItemsContainer">
                        {{-- Dynamically populated by JS when modal opens --}}
                    </div>
                    <div id="poNoItems" class="text-center text-muted py-3 small" style="display:none;">
                        No demand items for this supplier.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="poSubmitBtn">
                        Create Purchase Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@else
<div class="card p-5 text-center text-muted">
    <i class="bi bi-box-seam fs-1 mb-3 d-block"></i>
    Select a trip above to view purchasing demand.
</div>
@endif
@endsection

@push('scripts')
<script>
// All demand data passed from server, keyed by supplier key
const demandBySupplier = @json($demandBySupplier);

document.getElementById('newPOModal').addEventListener('show.bs.modal', function (e) {
    const btn        = e.relatedTarget;
    const supplierId = btn?.dataset?.supplierId || '';
    const supplierName = btn?.dataset?.supplierName || '';

    // Set hidden supplier_id
    document.getElementById('poSupplierId').value = supplierId;
    document.getElementById('poModalTitle').textContent = supplierName
        ? `New PO — ${supplierName}`
        : 'New Purchase Order';

    // Show supplier info banner
    const infoEl = document.getElementById('poSupplierInfo');
    if (supplierName) {
        document.getElementById('poSupplierNameDisplay').textContent = supplierName;
        infoEl.style.display = 'block';
    } else {
        infoEl.style.display = 'none';
    }

    // Find the matching supplier group — keys are integers from PHP, compare as strings
    const groupKey = supplierId ? String(supplierId) : 'no_supplier';
    // PHP encodes integer keys as numbers in JSON, so check both string and number
    const group = demandBySupplier[groupKey]
               ?? demandBySupplier[parseInt(groupKey)]
               ?? demandBySupplier['no_supplier'];
    const rows = group ? group.rows : [];

    const container = document.getElementById('poItemsContainer');
    const noItems   = document.getElementById('poNoItems');
    container.innerHTML = '';

    if (!rows.length) {
        noItems.style.display = 'block';
        document.getElementById('poSubmitBtn').disabled = true;
        return;
    }

    noItems.style.display = 'none';
    document.getElementById('poSubmitBtn').disabled = false;

    rows.forEach((row, i) => {
        const div = document.createElement('div');
        div.className = 'row g-2 align-items-center mb-3 p-2 border rounded';
        div.innerHTML = `
            <input type="hidden" name="items[${i}][product_id]"         value="${row.product_id}">
            <input type="hidden" name="items[${i}][product_variant_id]" value="${row.variant_id || ''}">
            <div class="col-md-4 small">
                <strong>${row.product_name}</strong>
                ${row.variant_label ? `<br><span class="text-muted">${row.variant_label}</span>` : ''}
                ${row.product_code  ? `<br><span class="font-monospace text-muted" style="font-size:.7rem;">${row.product_code}</span>` : ''}
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Qty to Order</label>
                <input type="number" name="items[${i}][quantity_ordered]"
                    class="form-control form-control-sm" value="${row.total_demanded}" min="1" required>
                <div class="form-text">Demand: <strong>${row.total_demanded}</strong></div>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Unit Cost (Rp)</label>
                <input type="number" name="items[${i}][unit_cost]"
                    class="form-control form-control-sm" value="${row.unit_cost}" step="1000" min="0" required>
            </div>
            <div class="col-md-2 small text-muted pt-3">
                Stock: ${row.supplier_stock}
            </div>`;
        container.appendChild(div);
    });
});
</script>
@endpush
