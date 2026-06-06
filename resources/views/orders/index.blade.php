@extends('layouts.app')
@section('title', 'Orders')
@section('page-title', 'Orders')

@section('content')

@if(session('import_errors'))
<div class="alert alert-danger mb-3">
    <div class="fw-semibold mb-2"><i class="bi bi-x-circle-fill me-1"></i>Import blocked — fix these issues in your Excel file and try again:</div>
    <ul class="mb-0 ps-3">
        @foreach(session('import_errors') as $err)
            <li class="small">{{ $err }}</li>
        @endforeach
    </ul>
    <div class="mt-2 small text-muted">
        <strong>Tips:</strong> Product codes must exist in the selected trip. Color and Size must exactly match the variant names in the system.
    </div>
</div>
@endif
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
    <div class="col-auto d-flex gap-2">
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-arrow-down-up me-1"></i>Import / Export
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:240px;">
                <li><h6 class="dropdown-header">Export</h6></li>
                <li>
                    <a class="dropdown-item" href="{{ route('orders.export', request()->only('trip_id')) }}">
                        <i class="bi bi-download me-2 text-success"></i>Export orders as Excel
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="{{ route('orders.items.export', request()->only('trip_id')) }}">
                        <i class="bi bi-download me-2 text-info"></i>Export order items as Excel
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Import</h6></li>
                <li>
                    <a class="dropdown-item" href="{{ route('orders.import.template') }}">
                        <i class="bi bi-file-earmark-spreadsheet me-2 text-secondary"></i>Download template (.xlsx)
                    </a>
                </li>
                <li>
                    <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#importOrderModal">
                        <i class="bi bi-upload me-2 text-primary"></i>Import orders from Excel
                    </button>
                </li>
            </ul>
        </div>
        <a href="{{ route('orders.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Order</a>
    </div>
</div>

{{-- Import Order Modal --}}
<div class="modal fade" id="importOrderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Import Orders from Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">
                    <strong>Columns (13):</strong> No · Name · Phone · <strong>Type</strong> · Area · Code · Color · Size · Qty · Price · DP · Date of DP · Notes<br>
                    <span class="text-muted d-block mt-1">
                        • <strong>Type</strong>: <code>customer</code> / <code>reseller</code> / <code>selected_customer</code> — leave blank for default (customer).<br>
                        • <strong>Each row = 1 item line</strong> with its own Qty.<br>
                        • Repeat customer name each row, or leave blank to continue same customer.<br>
                        • Products must exist in the selected trip. Color/Size must match exactly.
                    </span>
                    <a href="{{ route('orders.import.template') }}" class="small mt-1 d-inline-block">
                        <i class="bi bi-download me-1"></i>Download template (.xlsx)
                    </a>
                </div>
                <form method="POST" action="{{ route('orders.import') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Trip <span class="text-danger">*</span></label>
                        <select name="trip_id" class="form-select" required>
                            <option value="">Select trip…</option>
                            @foreach(\App\Models\Trip::orderByDesc('id')->get() as $trip)
                                <option value="{{ $trip->id }}" {{ request('trip_id') == $trip->id ? 'selected' : '' }}>
                                    {{ $trip->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text text-muted">Products must exist in this trip. Color/Size must match exactly.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Excel File (.xlsx) <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" accept=".xlsx,.xls" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-upload me-1"></i>Import Orders
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
