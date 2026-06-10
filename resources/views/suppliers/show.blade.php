@extends('layouts.app')
@section('title', $supplier->name)
@section('page-title', $supplier->name)

@section('content')
<div class="d-flex gap-2 mb-3">
@if(auth()->user()->hasPermission('suppliers.edit'))
    <a href="{{ route('suppliers.edit', $supplier) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-pencil me-1"></i>Edit
    </a>
    @endif
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Contact</div><div class="fw-semibold">{{ $supplier->contact_person ?? '—' }}</div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Phone</div><div>{{ $supplier->phone ?? '—' }}</div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Country</div><div>{{ $supplier->country ?? '—' }}</div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="text-muted small">Status</div>
        <div>@if($supplier->is_active)<span class="badge bg-success">Active</span>@else<span class="badge bg-secondary">Inactive</span>@endif</div>
    </div></div>
</div>

@if($supplier->notes)
<div class="card mb-3 p-3">
    <div class="text-muted small mb-1">Notes</div>
    <div>{{ $supplier->notes }}</div>
</div>
@endif

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">Products ({{ $supplier->products->count() }})</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light"><tr><th>Product</th><th>Trip</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($supplier->products as $product)
                        <tr>
                            <td class="fw-semibold">
                                <a href="{{ route('products.show', $product) }}" class="text-decoration-none">{{ $product->name }}</a>
                                @if($product->product_code)<div class="text-muted font-monospace" style="font-size:.7rem;">{{ $product->product_code }}</div>@endif
                            </td>
                            <td class="text-muted">{{ $product->trip->name }}</td>
                            <td><span class="badge {{ $product->status === 'active' ? 'bg-success' : 'bg-secondary' }}">{{ ucfirst($product->status) }}</span></td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">No products</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">Purchase Orders ({{ $supplier->purchaseOrders->count() }})</div>
            <ul class="list-group list-group-flush">
                @forelse($supplier->purchaseOrders as $po)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="font-monospace small fw-semibold">{{ $po->po_number }}</div>
                        <div class="text-muted small">{{ $po->trip->name }} · Rp {{ number_format($po->total_amount, 0, ',', '.') }}</div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="badge {{ match($po->status) { 'arrived'=>'bg-success','confirmed'=>'bg-primary','submitted'=>'bg-warning text-dark',default=>'bg-secondary' } }}">{{ ucfirst($po->status) }}</span>
                        <a href="{{ route('purchasing.show', $po) }}" class="btn btn-sm btn-outline-secondary">View</a>
                    </div>
                </li>
                @empty
                <li class="list-group-item text-center text-muted small py-3">No purchase orders</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection