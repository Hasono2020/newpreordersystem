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
    <div class="col-auto">
        <a href="{{ route('products.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Product</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Product</th><th>Trip</th><th>Price</th><th>Variants</th><th>Status</th><th>Orders</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($products as $product)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $product->name }}</div>
                        @if($product->brand)<div class="text-muted small">{{ $product->brand }}</div>@endif
                    </td>
                    <td class="small">{{ $product->trip->name }}</td>
                    <td>Rp {{ number_format($product->price, 0, ',', '.') }}</td>
                    <td class="small text-muted">—</td>
                    <td>
                        <span class="badge {{ $product->status === 'active' ? 'bg-success' : ($product->status === 'arrived' ? 'bg-info' : 'bg-secondary') }}">
                            {{ ucfirst($product->status) }}
                        </span>
                    </td>
                    <td>{{ $product->order_items_count }}</td>
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
