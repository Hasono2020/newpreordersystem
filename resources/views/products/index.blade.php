@extends('layouts.app')
@section('title', 'Products')
@section('page-title', 'Products')

@section('content')

@if(session('import_errors'))
<div class="alert alert-danger mb-3">
    <div class="fw-semibold mb-2"><i class="bi bi-x-circle-fill me-1"></i>Import blocked — fix these issues and try again:</div>
    <ul class="mb-0 ps-3">
        @foreach(session('import_errors') as $err)
            <li class="small">{{ $err }}</li>
        @endforeach
    </ul>
</div>
@endif
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2" method="GET" action="{{ route('products.index') }}">
            <input type="text" name="search" class="form-control form-control-sm"
                placeholder="Search name or code…"
                value="{{ request('search') }}" style="width:200px;">
            <select name="trip_id" class="form-select form-select-sm" style="width:auto;">
                <option value="">All Trips</option>
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}" {{ request('trip_id') == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-search me-1"></i>Filter
            </button>
            @if(request('search') || request('trip_id'))
                <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-secondary">✕ Clear</a>
            @endif
        </form>
    </div>
    <div class="col-auto d-flex gap-2">
        @if(auth()->user()->hasPermission('products.import'))
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-arrow-down-up me-1"></i>Import / Export
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:240px;">
                <li><h6 class="dropdown-header">Export</h6></li>
                <li>
                    <a class="dropdown-item" href="{{ route('products.export', request()->only('trip_id')) }}">
                        <i class="bi bi-download me-2 text-success"></i>Export{{ request('trip_id') ? ' (this trip)' : ' all' }} as Excel
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Import</h6></li>
                <li>
                    <a class="dropdown-item" href="{{ route('products.import.template') }}">
                        <i class="bi bi-file-earmark-spreadsheet me-2 text-secondary"></i>Download template (.xlsx)
                    </a>
                </li>
                <li>
                    <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#importProductModal">
                        <i class="bi bi-upload me-2 text-primary"></i>Import products from Excel
                    </button>
                </li>
            </ul>
        </div>
        @endif
        @if(auth()->user()->hasPermission('products.delete'))
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-danger dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-trash3 me-1"></i>Delete
            </button>
            <ul class="dropdown-menu">
                <li>
                    <button class="dropdown-item" id="deleteSelectedBtn" disabled onclick="confirmBulkDelete('selected')">
                        <i class="bi bi-check2-square me-2"></i>Delete selected
                        <span class="badge bg-danger ms-1" id="selectedCount" style="display:none;"></span>
                    </button>
                </li>
                <li>
                    <button class="dropdown-item text-danger" onclick="confirmBulkDelete('no_orders')">
                        <i class="bi bi-collection me-2"></i>Delete all with no orders
                    </button>
                </li>
            </ul>
        </div>
        @endif
        @if(auth()->user()->hasPermission('products.create'))
        <a href="{{ route('products.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Product</a>
        @endif
    </div>
</div>

{{-- Import Product Modal --}}
<div class="modal fade" id="importProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Import Products from Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">
                    <strong>Columns (14) — Product Import format:</strong><br>
                    <code class="small">Trip · Name · Code · SKU · Brand · Supplier · Price · Weight (gram) · Excluded from Promo · Status · Color · Size · Price Adjustment · Supplier Stock</code>
                    <span class="text-muted mt-1 d-block">
                        • <strong>One row per variant.</strong> Repeat the same Code on each row for different variants.<br>
                        • All rows must have Trip, Name, and Code — any missing value blocks the entire import.<br>
                        • Supplier is auto-created if it doesn't exist yet.<br>
                        • <strong>Excluded from Promo:</strong> yes / no &nbsp;|&nbsp; <strong>Status:</strong> active / closed
                    </span>
                    <a href="{{ route('products.import.template') }}" class="small mt-1 d-inline-block">
                        <i class="bi bi-download me-1"></i>Download template (.xlsx)
                    </a>
                </div>
                <form method="POST" action="{{ route('products.import') }}" enctype="multipart/form-data">
                    @csrf
                    <input type="file" name="file" class="form-control mb-3" accept=".xlsx" required>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-upload me-1"></i>Import
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="{{ route('products.bulk-destroy') }}" id="bulkDeleteForm">
    @csrf
    <input type="hidden" name="action" id="bulkAction" value="">
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 responsive-cards">
            <thead class="table-light">
                <tr>
                    @if(auth()->user()->isAdmin())
                    <th style="width:36px;">
                        <input type="checkbox" id="selectAll" class="form-check-input">
                    </th>
                    @endif
                    <th>Product</th><th>Code</th><th>Trip</th><th>Price</th><th>Weight</th><th>Status</th><th>Orders</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                <tr>
                    @if(auth()->user()->isAdmin())
                    <td class="no-label">
                        <input type="checkbox" name="product_ids[]" value="{{ $product->id }}"
                            class="form-check-input product-checkbox" form="bulkDeleteForm">
                    </td>
                    @endif
                    <td data-label="Product">
                        <div class="d-flex align-items-center gap-2">
                            @if($product->image)
                                <img src="{{ asset('storage/'.$product->image) }}" width="36" height="36"
                                    style="object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;" alt="">
                            @else
                                <div style="width:36px;height:36px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;">
                                    <i class="bi bi-image text-muted" style="font-size:.8rem;"></i>
                                </div>
                            @endif
                            <div>
                                <div class="fw-semibold">{{ $product->name }}</div>
                                @if($product->brand)
                                    <div class="text-muted small">{{ $product->brand }}</div>
                                @endif
                                @if($product->supplier)
                                    <div class="text-muted small"><i class="bi bi-building me-1"></i>{{ $product->supplier->name }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td data-label="Code">
                        @if($product->product_code)
                            <span class="badge bg-light text-dark border font-monospace">{{ $product->product_code }}</span>
                        @else
                            <span class="text-muted small">—</span>
                        @endif
                    </td>
                    <td class="small text-muted" data-label="Trip">{{ $product->trip->name }}</td>
                    <td class="small" data-label="Price">Rp {{ number_format($product->price, 0, ',', '.') }}</td>
                    <td class="small text-muted" data-label="Weight">
                        @if($product->weight_gram)
                            {{ $product->weight_gram }}g
                        @else
                            <span class="text-warning" title="Weight not set"><i class="bi bi-exclamation-triangle-fill"></i></span>
                        @endif
                    </td>
                    <td data-label="Status">
                        <span class="badge {{ $product->status === 'active' ? 'bg-success' : ($product->status === 'arrived' ? 'bg-info' : 'bg-secondary') }}">
                            {{ ucfirst($product->status) }}
                        </span>
                    </td>
                    <td class="text-center" data-label="Orders">{{ $product->order_items_count }}</td>
                    <td class="cell-actions no-label">
                        <a href="{{ route('products.show', $product) }}" class="btn btn-sm btn-outline-primary">View</a>
@if(auth()->user()->hasPermission('products.edit'))
                        <a href="{{ route('products.edit', $product) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="{{ auth()->user()->isAdmin() ? 9 : 8 }}" class="text-center text-muted py-4">No products yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted">{{ $products->total() }} product(s)</span>
            <form method="GET" action="{{ route('products.index') }}" class="d-flex align-items-center gap-1 ms-2">
                @foreach(request()->except('per_page','page') as $k => $v)
                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                @endforeach
                <label class="small text-muted mb-0">Show:</label>
                <select name="per_page" class="form-select form-select-sm" style="width:70px;" onchange="this.form.submit()">
                    @foreach([20,50,100,200] as $n)
                        <option value="{{ $n }}" {{ $perPage==$n?'selected':'' }}>{{ $n }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div>{{ $products->links() }}</div>
    </div>
</div>

@if(auth()->user()->isAdmin())
<script>
const selectAll   = document.getElementById('selectAll');
const countBadge  = document.getElementById('selectedCount');
const deleteBtn   = document.getElementById('deleteSelectedBtn');

function updateCount() {
    const checked = document.querySelectorAll('.product-checkbox:checked').length;
    deleteBtn.disabled = checked === 0;
    countBadge.style.display = checked > 0 ? 'inline-block' : 'none';
    countBadge.textContent = checked;
}

selectAll?.addEventListener('change', () => {
    document.querySelectorAll('.product-checkbox').forEach(c => c.checked = selectAll.checked);
    updateCount();
});

document.querySelectorAll('.product-checkbox').forEach(c => c.addEventListener('change', updateCount));

function confirmBulkDelete(action) {
    const form = document.getElementById('bulkDeleteForm');
    document.getElementById('bulkAction').value = action;
    const msg = action === 'selected'
        ? `Delete ${document.querySelectorAll('.product-checkbox:checked').length} selected product(s)? This cannot be undone.`
        : 'Delete ALL products with no orders? This cannot be undone.';
    if (confirm(msg)) form.submit();
}
</script>
@endif
@endsection