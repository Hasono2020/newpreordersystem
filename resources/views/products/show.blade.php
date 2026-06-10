@extends('layouts.app')
@section('title', $product->name)
@section('page-title', $product->name)

@section('content')
<div class="d-flex gap-2 align-items-center mb-3">
    <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
@if(auth()->user()->hasPermission('products.edit'))
    <a href="{{ route('products.edit', $product) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-pencil me-1"></i>Edit
    </a>
    @endif
    <span class="badge {{ $product->status === 'active' ? 'bg-success' : 'bg-secondary' }} align-self-center">
        {{ ucfirst($product->status) }}
    </span>
    <h5 class="mb-0 ms-2 fw-bold">{{ $product->name }}</h5>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            @if($product->image)
                <img src="{{ asset('storage/'.$product->image) }}" class="img-fluid rounded mb-3" alt="{{ $product->name }}">
            @endif
            <table class="table table-sm mb-0 small">
                <tr><td class="text-muted">Trip</td><td>{{ $product->trip->name }}</td></tr>
                <tr><td class="text-muted">Brand</td><td>{{ $product->brand ?? '—' }}</td></tr>
                <tr><td class="text-muted">Product Code</td><td class="font-monospace">{{ $product->product_code ?? '—' }}</td></tr>
                <tr><td class="text-muted">Base Price</td><td>Rp {{ number_format($product->price, 0, ',', '.') }}</td></tr>
                <tr><td class="text-muted">Weight</td><td>{{ $product->weight_gram ? number_format($product->weight_gram).' g' : '—' }}</td></tr>
                <tr>
                    <td class="text-muted">Promo</td>
                    <td>
                        @if($product->excluded_from_promo)
                            <span class="badge bg-danger">Excluded from promos</span>
                        @else
                            <span class="badge bg-success">Eligible</span>
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        {{-- Add Variant --}}
        @if(auth()->user()->hasPermission('products.edit'))
        <div class="card p-3">
            <div class="fw-semibold mb-3">Add Variant</div>
            <form method="POST" action="{{ route('products.variants.store', $product) }}">
                @csrf
                <div class="mb-2"><input type="text" name="color" class="form-control form-control-sm" placeholder="Color"></div>
                <div class="mb-2"><input type="text" name="size" class="form-control form-control-sm" placeholder="Size"></div>
                <div class="mb-3"><input type="number" name="price_adjustment" class="form-control form-control-sm" placeholder="Price adjustment (Rp)" value="0" step="1000"></div>
                <button type="submit" class="btn btn-sm btn-primary w-100">Add Variant</button>
            </form>
        </div>
        @endif
    </div>

    <div class="col-lg-8">
        {{-- Variants --}}
        <div class="card mb-3">
            <div class="card-header bg-white py-3 fw-semibold">Variants & Stock</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr><th>Color / Size</th><th>Price</th><th>Supplier Stock</th><th>Allocated</th><th>Remaining</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($product->variants as $variant)
                        <tr>
                            <td class="fw-semibold">{{ $variant->label }}</td>
                            <td>Rp {{ number_format($variant->final_price, 0, ',', '.') }}</td>
                            <td>
                                @if($variant->allocated_qty > 0)
                                    <span class="badge bg-success">{{ $variant->supplier_stock }}</span>
                                    <span class="text-muted small ms-1" title="Stock locked after allocation">🔒</span>
                                @elseif(auth()->user()->hasPermission('products.edit'))
                                    <form method="POST" action="{{ route('products.variants.update', [$product, $variant]) }}" class="d-inline">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="color" value="{{ $variant->color }}">
                                        <input type="hidden" name="size" value="{{ $variant->size }}">
                                        <input type="hidden" name="price_adjustment" value="{{ $variant->price_adjustment }}">
                                        <div class="input-group input-group-sm" style="width:100px;">
                                            <input type="number" name="supplier_stock" class="form-control form-control-sm" value="{{ $variant->supplier_stock }}" min="0">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm">✓</button>
                                        </div>
                                    </form>
                                @else
                                    <span class="badge bg-light text-dark border">{{ $variant->supplier_stock }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="{{ $variant->allocated_qty > 0 ? 'text-success fw-semibold' : 'text-muted' }}">
                                    {{ $variant->allocated_qty }}
                                </span>
                            </td>
                            <td>
                                <span class="{{ $variant->remaining_stock < 0 ? 'text-danger fw-bold' : ($variant->remaining_stock == 0 && $variant->allocated_qty > 0 ? 'text-success' : '') }}">
                                    {{ $variant->remaining_stock }}
                                </span>
                            </td>
                            <td>
                                @php $hasOrders = $variant->orderItems()->exists(); @endphp
                                @if(!$hasOrders && $variant->allocated_qty == 0 && auth()->user()->isAdmin())
                                    <form method="POST" action="{{ route('products.variants.destroy', [$product, $variant]) }}"
                                        onsubmit="return confirm('Delete this variant? This cannot be undone.')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
                                    </form>
                                @else
                                    <span class="text-muted small" title="Cannot delete — variant has orders">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No variants — orders will use base product</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Orders using this product --}}
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">Orders for this Product</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr><th>Customer</th><th>Variant</th><th>Qty</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($product->orderItems as $item)
                        <tr>
                            <td>{{ $item->order->customer->name }}</td>
                            <td>{{ $item->variant?->label ?? 'Default' }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td>{!! $item->status_badge !!}</td>
                            <td><a href="{{ route('orders.show', $item->order) }}" class="btn btn-sm btn-outline-secondary">Order</a></td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No orders yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection