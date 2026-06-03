@extends('layouts.app')
@section('title', 'Customers')
@section('page-title', 'Customers')

@section('content')
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name or phone…" value="{{ request('search') }}">
            <select name="type" class="form-select form-select-sm" style="width:auto;">
                <option value="">All types</option>
                <option value="customer" {{ request('type')=='customer'?'selected':'' }}>Customer</option>
                <option value="reseller" {{ request('type')=='reseller'?'selected':'' }}>Reseller</option>
                <option value="selected_customer" {{ request('type')=='selected_customer'?'selected':'' }}>Selected Customer</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
            @if(request('search') || request('type'))
                <a href="{{ route('customers.index') }}" class="btn btn-sm btn-link">Clear</a>
            @endif
        </form>
    </div>
    <div class="col-auto">
        <a href="{{ route('customers.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Customer</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Phone</th><th>Type</th><th>Orders</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                <tr>
                    <td class="fw-semibold">{{ $customer->name }}</td>
                    <td>{{ $customer->phone ?? '—' }}</td>
                    <td>
                        @if($customer->type === 'reseller')
                            <span class="badge bg-purple" style="background:#7c3aed!important;">Reseller</span>
                        @elseif($customer->type === 'selected_customer')
                            <span class="badge bg-info text-dark">Selected</span>
                        @else
                            <span class="badge bg-secondary">Customer</span>
                        @endif
                    </td>
                    <td>{{ $customer->orders_count }}</td>
                    <td>
                        <a href="{{ route('customers.show', $customer) }}" class="btn btn-sm btn-outline-primary">View</a>
                        <a href="{{ route('customers.edit', $customer) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No customers found</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $customers->links() }}</div>
</div>
@endsection
