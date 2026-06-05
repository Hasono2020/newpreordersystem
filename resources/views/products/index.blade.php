@extends('layouts.app')
@section('title', 'Products')
@section('page-title', 'Products')

@section('content')
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2">
            <select name="trip_id" class="form-select form-select-sm" style="width:auto;">
                <option value="">All Trips</option>
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}" {{ request('trip_id') == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
    </div>
    <div class="col-auto d-flex gap-2">
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
        <a href="{{ route('products.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Product</a>
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
                    <strong>Excel columns:</strong> trip · name · product_code · sku · brand · supplier · price · weight_gram · excluded_from_promo · notes<br>
                    <span class="text-muted">
                        • <strong>Trip</strong> must match an existing trip name.<br>
                        • <strong>product_code</strong> must be unique — duplicates are skipped.<br>
                        • <strong>excluded_from_promo</strong>: use <code>yes</code> or <code>no</code>.<br>
                        • <strong>Supplier</strong> matched by name from your suppliers list.
                    </span><br>
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

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Product</th><th>Code</th><th>Trip</th><th>Price</th><th>Weight</th><th>Status</th><th>Orders</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                <tr>
                    <td>
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
                    <td>
                        @if($product->product_code)
                            <span class="badge bg-light text-dark border font-monospace">{{ $product->product_code }}</span>
                        @else
                            <span class="text-muted small">—</span>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $product->trip->name }}</td>
                    <td class="small">Rp {{ number_format($product->price, 0, ',', '.') }}</td>
                    <td class="small text-muted">
                        @if($product->weight_gram)
                            {{ $product->weight_gram }}g
                        @else
                            <span class="text-warning" title="Weight not set"><i class="bi bi-exclamation-triangle-fill"></i></span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $product->status === 'active' ? 'bg-success' : ($product->status === 'arrived' ? 'bg-info' : 'bg-secondary') }}">
                            {{ ucfirst($product->status) }}
                        </span>
                    </td>
                    <td class="text-center">{{ $product->order_items_count }}</td>
                    <td>
                        <a href="{{ route('products.show', $product) }}" class="btn btn-sm btn-outline-primary">View</a>
                        <a href="{{ route('products.edit', $product) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No products yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $products->links() }}</div>
</div>
@endsection
