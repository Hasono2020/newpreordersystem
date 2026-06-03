@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card blue p-3">
            <div class="text-muted small">Open Trips</div>
            <div class="fs-2 fw-bold">{{ $stats['trips_open'] }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card green p-3">
            <div class="text-muted small">Orders Today</div>
            <div class="fs-2 fw-bold">{{ $stats['orders_today'] }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card yellow p-3">
            <div class="text-muted small">Unpaid Orders</div>
            <div class="fs-2 fw-bold">{{ $stats['unpaid_orders'] }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card red p-3">
            <div class="text-muted small">Total Customers</div>
            <div class="fs-2 fw-bold">{{ $stats['total_customers'] }}</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-semibold">Recent Orders</span>
                <a href="{{ route('orders.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New Order</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order #</th><th>Customer</th><th>Trip</th><th>Total</th><th>Payment</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOrders as $order)
                        <tr>
                            <td class="font-monospace small">{{ $order->order_number }}</td>
                            <td>{{ $order->customer->name }}</td>
                            <td class="small text-muted">{{ $order->trip->name }}</td>
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
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-semibold">Active Trips</span>
                <a href="{{ route('trips.create') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i></a>
            </div>
            <ul class="list-group list-group-flush">
                @forelse($activeTrips as $trip)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold small">{{ $trip->name }}</div>
                        <div class="text-muted" style="font-size:.75rem;">{{ $trip->destination }} · {{ $trip->orders_count }} orders</div>
                    </div>
                    {!! $trip->status_badge !!}
                </li>
                @empty
                <li class="list-group-item text-muted text-center py-3 small">No active trips</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
