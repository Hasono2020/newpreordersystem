@extends('layouts.app')
@section('title', 'Orders')
@section('page-title', 'Orders')

@section('content')
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2 flex-wrap">
            <input type="text" name="search" class="form-control form-control-sm" style="width:200px;" placeholder="Order # or customer…" value="{{ request('search') }}">
            <select name="trip_id" class="form-select form-select-sm" style="width:auto;">
                <option value="">All Trips</option>
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}" {{ request('trip_id') == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
                @endforeach
            </select>
            <select name="payment_status" class="form-select form-select-sm" style="width:auto;">
                <option value="">All Status</option>
                <option value="unpaid" {{ request('payment_status')=='unpaid'?'selected':'' }}>Unpaid</option>
                <option value="partial" {{ request('payment_status')=='partial'?'selected':'' }}>Partial</option>
                <option value="paid" {{ request('payment_status')=='paid'?'selected':'' }}>Paid</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
            @if(request()->anyFilled(['search','trip_id','payment_status']))
                <a href="{{ route('orders.index') }}" class="btn btn-sm btn-link">Clear</a>
            @endif
        </form>
    </div>
    <div class="col-auto">
        <a href="{{ route('orders.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Order</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Order #</th><th>Customer</th><th>Trip</th><th>Subtotal</th><th>Discount</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                <tr>
                    <td class="font-monospace small">{{ $order->order_number }}</td>
                    <td>
                        <div class="fw-semibold">{{ $order->customer->name }}</div>
                        <div class="text-muted" style="font-size:.72rem;">{{ $order->customer->type_label }}</div>
                    </td>
                    <td class="small text-muted">{{ $order->trip->name }}</td>
                    <td class="small">Rp {{ number_format($order->subtotal, 0, ',', '.') }}</td>
                    <td class="small text-success">
                        @if($order->discount_amount > 0) -Rp {{ number_format($order->discount_amount, 0, ',', '.') }} @else — @endif
                    </td>
                    <td class="fw-semibold">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td>
                    <td class="small text-success">Rp {{ number_format($order->deposit_paid, 0, ',', '.') }}</td>
                    <td class="small {{ $order->remaining_balance > 0 ? 'text-danger' : 'text-success' }}">
                        Rp {{ number_format($order->remaining_balance, 0, ',', '.') }}
                    </td>
                    <td>{!! $order->payment_status_badge !!}</td>
                    <td><a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-outline-primary">View</a></td>
                </tr>
                @empty
                <tr><td colspan="10" class="text-center text-muted py-4">No orders found</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $orders->links() }}</div>
</div>
@endsection
