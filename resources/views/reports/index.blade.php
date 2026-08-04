@extends('layouts.app')
@section('title', 'Reports')
@section('page-title', 'Reports')

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

{{-- ── Sales Recap Export ── --}}
<div class="export-card bg-white mb-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <div class="icon bg-success bg-opacity-10 text-success">
                <i class="bi bi-file-earmark-spreadsheet"></i>
            </div>
            <div>
                <div class="fw-semibold">Sales Recap (Excel)</div>
                <div class="small text-muted">
                    @if($selectedTrip)
                        Date, customer, and total sales for &ldquo;{{ $selectedTrip->name }}&rdquo; — with a grand total row.
                    @else
                        Select a trip above to export its sales recap.
                    @endif
                </div>
            </div>
        </div>
        @if($selectedTrip)
            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#salesRecapModal">
                <i class="bi bi-download me-1"></i>Export
            </button>
        @else
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                <i class="bi bi-download me-1"></i>Export
            </button>
        @endif
    </div>
</div>

@if($selectedTrip)
<div class="modal fade" id="salesRecapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ route('reports.export.sales-recap') }}">
                @csrf
                <input type="hidden" name="trip_id" value="{{ $selectedTrip->id }}">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm export — {{ $selectedTrip->name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">Re-enter your password to download the sales recap for this trip.</p>
                    <label class="form-label small fw-semibold">Password</label>
                    <input type="password" name="password" class="form-control" required autocomplete="current-password" autofocus>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-download me-1"></i>Confirm &amp; Download
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@if(session('error'))
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('salesRecapModal');
    if (el) new bootstrap.Modal(el).show();
});
</script>
@endif
@endif

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
                                <div class="fw-semibold font-monospace">{{ $p->product_code ?? '—' }}</div>
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

@endsection