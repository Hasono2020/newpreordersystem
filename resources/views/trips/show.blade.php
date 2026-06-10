@extends('layouts.app')
@section('title', $trip->name)
@section('page-title', $trip->name)

@section('content')
<div class="d-flex gap-2 mb-3">
    @if(auth()->user()->isAdmin())
    <a href="{{ route('trips.edit', $trip) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
    <form method="POST" action="{{ route('trips.destroy', $trip) }}"
        onsubmit="return confirm('Delete trip \'{{ $trip->name }}\'? This cannot be undone.')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Delete</button>
    </form>
    @endif
@if(auth()->user()->hasPermission('orders.create'))
    <a href="{{ route('orders.create', ['trip_id' => $trip->id]) }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Order</a>
    @endif
@if(auth()->user()->hasPermission('products.create'))
    <a href="{{ route('products.create', ['trip_id' => $trip->id]) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-tags me-1"></i>Add Product</a>
    @endif
    <a href="{{ route('purchasing.index', ['trip_id' => $trip->id]) }}" class="btn btn-outline-info btn-sm"><i class="bi bi-box-seam me-1"></i>Purchasing</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card p-3 text-center"><div class="text-muted small">Status</div><div class="mt-1">{!! $trip->status_badge !!}</div></div></div>
    <div class="col-md-3"><div class="card p-3 text-center"><div class="text-muted small">Destination</div><div class="fw-semibold">{{ $trip->destination ?? '—' }}</div></div></div>
    <div class="col-md-3"><div class="card p-3 text-center"><div class="text-muted small">Trip Date</div><div class="fw-semibold">{{ $trip->trip_date?->format('d M Y') ?? '—' }}</div></div></div>
    <div class="col-md-3"><div class="card p-3 text-center"><div class="text-muted small">Order Deadline</div><div class="fw-semibold">{{ $trip->order_deadline?->format('d M Y') ?? '—' }}</div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-3"><div class="card p-3 text-center"><div class="text-muted small">Total Orders</div><div class="fs-3 fw-bold">{{ $orderSummary['total'] }}</div></div></div>
    <div class="col-sm-3"><div class="card p-3 text-center stat-card red"><div class="text-muted small">Unpaid</div><div class="fs-3 fw-bold text-danger">{{ $orderSummary['unpaid'] }}</div></div></div>
    <div class="col-sm-3"><div class="card p-3 text-center stat-card yellow"><div class="text-muted small">Partial</div><div class="fs-3 fw-bold text-warning">{{ $orderSummary['partial'] }}</div></div></div>
    <div class="col-sm-3"><div class="card p-3 text-center stat-card green"><div class="text-muted small">Fully Paid</div><div class="fs-3 fw-bold text-success">{{ $orderSummary['paid'] }}</div></div></div>
</div>

<div class="row g-3">
    {{-- Products --}}
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-semibold">Products ({{ $trip->products->count() }})</span>
@if(auth()->user()->hasPermission('products.create'))
                <a href="{{ route('products.create', ['trip_id' => $trip->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i></a>
                @endif
            </div>
            <ul class="list-group list-group-flush">
                @forelse($trip->products as $product)
                <li class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold small">{{ $product->name }}</div>
                            <div class="text-muted" style="font-size:.75rem;">
                                Rp {{ number_format($product->price, 0, ',', '.') }}
                                @if($product->brand) · {{ $product->brand }} @endif
                            </div>
                            @if($product->variants->count())
                            <div class="mt-1">
                                @foreach($product->variants as $v)
                                    <span class="badge bg-light text-dark border me-1">{{ $v->label }}</span>
                                @endforeach
                            </div>
                            @endif
                        </div>
                        <a href="{{ route('products.show', $product) }}" class="btn btn-sm btn-outline-secondary">View</a>
                    </div>
                </li>
                @empty
                <li class="list-group-item text-muted small text-center py-3">No products yet</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- Orders --}}
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-semibold">Orders ({{ $trip->orders->count() }})</span>
@if(auth()->user()->hasPermission('orders.create'))
                <a href="{{ route('orders.create', ['trip_id' => $trip->id]) }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New</a>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light"><tr><th>Order #</th><th>Customer</th><th>Total</th><th>Payment</th><th></th></tr></thead>
                    <tbody>
                        @forelse($trip->orders as $order)
                        <tr>
                            <td class="font-monospace">{{ $order->order_number }}</td>
                            <td>{{ $order->customer->name }}</td>
                            <td>Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td>
                            <td>{!! $order->payment_status_badge !!}</td>
                            <td><a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
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