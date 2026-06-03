@extends('layouts.app')
@section('title', $customer->name)
@section('page-title', $customer->name)

@section('content')
<div class="d-flex gap-2 mb-3">
    <a href="{{ route('customers.edit', $customer) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    <a href="{{ route('orders.create', ['customer_id' => $customer->id]) }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New Order</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card p-3">
            <div class="small text-muted mb-1">Phone</div>
            <div>{{ $customer->phone ?? '—' }}</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3">
            <div class="small text-muted mb-1">Type</div>
            <div>{{ $customer->type_label }}</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3">
            <div class="small text-muted mb-1">Total Orders</div>
            <div class="fw-bold">{{ $customer->orders->count() }}</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white py-3 fw-semibold">Order History</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 small">
            <thead class="table-light">
                <tr><th>Order #</th><th>Trip</th><th>Items</th><th>Total</th><th>Payment</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($customer->orders as $order)
                <tr>
                    <td class="font-monospace">{{ $order->order_number }}</td>
                    <td>{{ $order->trip->name }}</td>
                    <td>{{ $order->items->count() }}</td>
                    <td>Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td>
                    <td>{!! $order->payment_status_badge !!}</td>
                    <td><a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-3">No orders yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
