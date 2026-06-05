@extends('layouts.app')
@section('title', 'Reports & Export')
@section('page-title', 'Reports & Export')

@push('styles')
<style>
.stat-card-r { border-radius:10px; padding:1.1rem 1.3rem; border:1px solid #e5e7eb; }
.export-card { border:1px solid #e5e7eb; border-radius:10px; padding:1.1rem 1.3rem; transition:box-shadow .15s; }
.export-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); }
.export-card .icon { width:40px; height:40px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
</style>
@endpush

@section('content')

{{-- Trip filter --}}
<form class="d-flex gap-2 mb-4 align-items-center">
    <label class="fw-semibold text-muted small mb-0">Filter by trip:</label>
    <select name="trip_id" class="form-select form-select-sm" style="width:260px;" onchange="this.form.submit()">
        <option value="">All Trips</option>
        @foreach($trips as $trip)
            <option value="{{ $trip->id }}" {{ $selectedTrip?->id == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
        @endforeach
    </select>
    @if($selectedTrip)
        <a href="{{ route('reports.index') }}" class="btn btn-sm btn-link">Clear</a>
    @endif
</form>

{{-- ── Summary Cards ── --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-r bg-white">
            <div class="text-muted small">Total Orders</div>
            <div class="fs-2 fw-bold">{{ number_format($summary['total_orders']) }}</div>
            <div class="small mt-1">
                <span class="text-success">{{ $summary['paid_orders'] }} paid</span> ·
                <span class="text-warning">{{ $summary['partial_orders'] }} partial</span> ·
                <span class="text-danger">{{ $summary['unpaid_orders'] }} unpaid</span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-r bg-white">
            <div class="text-muted small">Total Revenue</div>
            <div class="fs-4 fw-bold text-primary">Rp {{ number_format($summary['total_revenue'], 0, ',', '.') }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-r bg-white">
            <div class="text-muted small">Total Collected</div>
            <div class="fs-4 fw-bold text-success">Rp {{ number_format($summary['total_paid'], 0, ',', '.') }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-r bg-white">
            <div class="text-muted small">Outstanding Balance</div>
            <div class="fs-4 fw-bold text-danger">Rp {{ number_format($summary['total_unpaid'], 0, ',', '.') }}</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    {{-- Sales by Trip --}}
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-white py-3 fw-semibold">Sales by Trip</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr><th>Trip</th><th>Orders</th><th>Revenue</th><th>Collected</th></tr>
                    </thead>
                    <tbody>
                        @forelse($salesByTrip as $trip)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $trip->name }}</div>
                                {!! $trip->status_badge !!}
                            </td>
                            <td>{{ $trip->orders_count }}</td>
                            <td>Rp {{ number_format($trip->total_revenue ?? 0, 0, ',', '.') }}</td>
                            <td class="text-success">Rp {{ number_format($trip->total_paid ?? 0, 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No data</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Top Customers --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-white py-3 fw-semibold">Top Customers</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr><th>#</th><th>Customer</th><th>Orders</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                        @foreach($topCustomers as $i => $c)
                        <tr>
                            <td class="text-muted">{{ $i+1 }}</td>
                            <td>
                                <div class="fw-semibold">{{ $c->name }}</div>
                                <div class="text-muted" style="font-size:.72rem;">{{ $c->type_label }}</div>
                            </td>
                            <td>{{ $c->order_count }}</td>
                            <td>Rp {{ number_format($c->total_spent ?? 0, 0, ',', '.') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Top Products --}}
    <div class="col-lg-3">
        <div class="card h-100">
            <div class="card-header bg-white py-3 fw-semibold">Top Products</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light">
                        <tr><th>Product</th><th>Qty</th></tr>
                    </thead>
                    <tbody>
                        @foreach($topProducts as $p)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $p->name }}</div>
                                @if($p->product_code)
                                    <span class="font-monospace text-muted" style="font-size:.72rem;">{{ $p->product_code }}</span>
                                @endif
                            </td>
                            <td class="fw-bold">{{ $p->total_qty ?? 0 }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ── Export / Import Cards ── --}}
<div class="fw-semibold mb-3 text-muted small text-uppercase" style="letter-spacing:.06em;">
    <i class="bi bi-download me-1"></i>Export Data
</div>
<div class="row g-3 mb-4">
    @php $tripParam = $selectedTrip ? '?trip_id='.$selectedTrip->id : ''; @endphp

    <div class="col-sm-6 col-lg-3">
        <div class="export-card bg-white">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-cart3"></i></div>
                <div class="fw-semibold">Orders</div>
            </div>
            <div class="text-muted small mb-3">All orders with totals, discounts, payment status</div>
            <a href="{{ route('reports.export.orders') }}{{ $tripParam }}" class="btn btn-sm btn-primary w-100">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="export-card bg-white">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="icon bg-info bg-opacity-10 text-info"><i class="bi bi-list-check"></i></div>
                <div class="fw-semibold">Order Items (Detail)</div>
            </div>
            <div class="text-muted small mb-3">Line-by-line: product, variant, qty, price, status</div>
            <a href="{{ route('reports.export.items') }}{{ $tripParam }}" class="btn btn-sm btn-info text-white w-100">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="export-card bg-white">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="icon bg-success bg-opacity-10 text-success"><i class="bi bi-people"></i></div>
                <div class="fw-semibold">Customers</div>
            </div>
            <div class="text-muted small mb-3">All customers with type, phone, total orders & spend</div>
            <a href="{{ route('reports.export.customers') }}" class="btn btn-sm btn-success w-100">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="export-card bg-white">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-tags"></i></div>
                <div class="fw-semibold">Products</div>
            </div>
            <div class="text-muted small mb-3">Products with price, weight, promo flag, qty ordered</div>
            <a href="{{ route('reports.export.products') }}{{ $tripParam }}" class="btn btn-sm btn-warning text-dark w-100">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
    </div>
</div>

{{-- ── Import Card ── --}}
<div class="fw-semibold mb-3 text-muted small text-uppercase" style="letter-spacing:.06em;">
    <i class="bi bi-upload me-1"></i>Import Data
</div>
<div class="row g-3">
    {{-- Order Import --}}
    <div class="col-lg-6">
        <div class="export-card bg-white">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-cart-plus"></i></div>
                <div>
                    <div class="fw-semibold">Import Orders from Excel</div>
                    <div class="text-muted small">Same format as Contoh.xlsx</div>
                </div>
                <a href="{{ route('reports.import.orders.template') }}" class="btn btn-sm btn-outline-secondary ms-auto">
                    <i class="bi bi-download me-1"></i>Template
                </a>
            </div>
            <div class="alert alert-light border py-2 px-3 small mb-3">
                <strong>Columns (12 total):</strong> No · Name · Phone · Area · Code · Color · Size · <strong>Qty</strong> · Price · DP · Date of DP · Notes<br>
                <span class="text-muted mt-1 d-block">
                    • <strong>Name, Code, Qty</strong> are required per row.<br>
                    • <strong>Phone, Area, DP</strong> only needed on the <em>first row</em> of each customer — leave blank on subsequent rows.<br>
                    • Rows with the same Name are grouped into one order. Customer matched by phone first, then name.<br>
                    • Products must exist in the selected trip (matched by product code).<br>
                    • Date format: <code>YYYY-MM-DD</code> or <code>DD/MM/YYYY</code>.
                </span>
            </div>
            <form method="POST" action="{{ route('reports.import.orders') }}" enctype="multipart/form-data">
                @csrf
                <div class="row g-2">
                    <div class="col-md-5">
                        <select name="trip_id" class="form-select form-select-sm" required>
                            <option value="">Select trip…</option>
                            @foreach($trips as $trip)
                                <option value="{{ $trip->id }}" {{ $selectedTrip?->id == $trip->id ? 'selected' : '' }}>
                                    {{ $trip->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input type="file" name="file" class="form-control form-control-sm" accept=".xlsx,.xls" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Import</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Customer Import --}}
    <div class="col-lg-6">
        <div class="export-card bg-white">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="icon bg-success bg-opacity-10 text-success"><i class="bi bi-people"></i></div>
                <div class="fw-semibold">Import Customers</div>
            </div>
            <div class="text-muted small mb-3">
                CSV columns: <code>name, phone, type, address, notes</code><br>
                Existing customers (matched by name) are skipped.
            </div>
            <form method="POST" action="{{ route('reports.import.customers') }}" enctype="multipart/form-data">
                @csrf
                <div class="input-group">
                    <input type="file" name="file" class="form-control form-control-sm" accept=".csv,.txt" required>
                    <button type="submit" class="btn btn-sm btn-success">Import</button>
                </div>
            </form>
            <div class="mt-2">
                <a href="{{ route('reports.export.customers') }}" class="small text-muted text-decoration-none">
                    <i class="bi bi-download me-1"></i>Export existing customers as CSV reference
                </a>
            </div>
        </div>
    </div>
</div>

@endsection
