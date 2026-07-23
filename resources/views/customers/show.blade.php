@extends('layouts.app')
@section('title', $customer->name)
@section('page-title', $customer->name)

@section('content')
<div class="d-flex gap-2 mb-3">
    <a href="{{ \App\Http\Middleware\RememberListUrl::returnUrl('customers') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
@if(auth()->user()->hasPermission('customers.edit'))
    <a href="{{ route('customers.edit', $customer) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-pencil me-1"></i>Edit
    </a>
    @if($customer->default_shipping_area_id)
    <form method="POST" action="{{ route('customers.apply-shipping', $customer) }}" class="d-inline"
          onsubmit="return confirm('Apply {{ $customer->defaultShippingArea->name }} to all orders in non-closed trips and recalculate? This affects all payment statuses.')">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-arrow-repeat me-1"></i>Apply Shipping to Orders
        </button>
    </form>
    @endif
    @endif
@if(auth()->user()->hasPermission('orders.create'))
    <a href="{{ route('orders.create', ['customer_id' => $customer->id]) }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>New Order
    </a>
    @endif
    <a href="{{ route('orders.index', ['search' => $customer->name]) }}" class="btn btn-sm btn-outline-info ms-auto">
        <i class="bi bi-receipt me-1"></i>View Orders
    </a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">
                <i class="bi bi-person me-2"></i>Profile
            </div>
            <div class="card-body p-0">
                <table class="table table-borderless mb-0 small">
                    <tr>
                        <td class="text-muted ps-3" style="width:38%">Name</td>
                        <td class="fw-semibold">{{ $customer->name }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Phone</td>
                        <td>
                            @if($customer->phone)
                                <a href="https://wa.me/{{ preg_replace('/\D/', '', $customer->phone) }}" target="_blank" class="text-decoration-none">
                                    📱 {{ $customer->phone }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Type</td>
                        <td>
                            @if($customer->type === 'reseller')
                                <span class="badge" style="background:#7c3aed;">Reseller</span>
                            @elseif($customer->type === 'selected_customer')
                                <span class="badge bg-info text-dark">Selected Customer</span>
                            @else
                                <span class="badge bg-secondary">Customer</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Shipping Area</td>
                        <td>
                            @if($customer->defaultShippingArea)
                                <span class="text-success fw-semibold">{{ $customer->defaultShippingArea->name }}</span>
                                <div class="text-muted" style="font-size:.72rem;">
                                    @if($customer->defaultShippingArea->isFlatFee())
                                        Flat Rp {{ number_format($customer->defaultShippingArea->flat_fee, 0, ',', '.') }}
                                    @else
                                        Rp {{ number_format($customer->defaultShippingArea->price_per_kg, 0, ',', '.') }}/kg
                                    @endif
                                </div>
                            @else
                                <span class="text-danger small">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Not set
                                </span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Cargo</td>
                        <td>
                            @if($customer->use_cargo)
                                <span class="badge bg-info-subtle text-info-emphasis">
                                    <i class="bi bi-box-seam me-1"></i>Yes — +1kg per shipment
                                </span>
                            @else
                                <span class="text-muted small">No</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Address</td>
                        <td>{{ $customer->address ?: '—' }}</td>
                    </tr>
                    @if($customer->notes)
                    <tr>
                        <td class="text-muted ps-3">Notes</td>
                        <td class="small">{{ $customer->notes }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="text-muted ps-3">Total Orders</td>
                        <td class="fw-bold">{{ $customer->orders->count() }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Member Since</td>
                        <td class="small text-muted">{{ $customer->created_at->format('d M Y') }}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        {{-- Quick stats --}}
        @php
            $totalSpent  = $customer->orders->sum('total_amount');
            $totalPaid   = $customer->orders->sum('deposit_paid');
            $totalUnpaid = $totalSpent - $totalPaid;
        @endphp
        <div class="row g-3 mb-3">
            <div class="col-4">
                <div class="card p-3 text-center">
                    <div class="small text-muted mb-1">Total Orders</div>
                    <div class="fw-bold fs-5">{{ $customer->orders->count() }}</div>
                </div>
            </div>
            <div class="col-4">
                <div class="card p-3 text-center">
                    <div class="small text-muted mb-1">Total Spent</div>
                    <div class="fw-bold text-primary small">Rp {{ number_format($totalSpent, 0, ',', '.') }}</div>
                </div>
            </div>
            <div class="col-4">
                <div class="card p-3 text-center">
                    <div class="small text-muted mb-1">Balance Due</div>
                    <div class="fw-bold {{ $totalUnpaid > 0 ? 'text-danger' : 'text-success' }} small">
                        Rp {{ number_format($totalUnpaid, 0, ',', '.') }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent orders (compact, max 5) --}}
        <div class="card">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Recent Orders</span>
                <a href="{{ route('orders.index', ['search' => $customer->name]) }}" class="btn btn-sm btn-outline-secondary">
                    View all →
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr><th>Order #</th><th>Trip</th><th>Total</th><th>Payment</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($customer->orders->sortByDesc('created_at')->take(5) as $order)
                        <tr>
                            <td class="font-monospace small">{{ $order->order_number }}</td>
                            <td class="small text-muted">{{ $order->trip->name }}</td>
                            <td>Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td>
                            <td>{!! $order->payment_status_badge !!}</td>
                            <td><a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-outline-secondary py-0 px-2">View</a></td>
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