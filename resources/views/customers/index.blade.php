@extends('layouts.app')
@section('title', 'Customers')
@section('page-title', 'Customers')

@section('content')

{{-- Import validation errors --}}
@if(session('import_errors'))
<div class="alert alert-danger mb-3">
    <div class="fw-semibold mb-2">
        <i class="bi bi-x-circle-fill me-1"></i>
        Import blocked — fix these issues in your Excel file and try again:
    </div>
    <ul class="mb-0 ps-3">
        @foreach(session('import_errors') as $err)
            <li class="small">{{ $err }}</li>
        @endforeach
    </ul>
    <div class="mt-2 small text-muted">
        <strong>Required columns:</strong> name · phone · type · <strong>shipping_area</strong> · address · notes<br>
        Make sure every customer row has a name, phone, and shipping area filled in.
    </div>
</div>
@endif

@if($noAreaCount > 0 && !request('shipping_area'))
<div class="alert alert-warning d-flex justify-content-between align-items-center py-2 mb-3">
    <div class="small">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>{{ $noAreaCount }}</strong> customer(s) have no shipping area set yet.
    </div>
    <a href="{{ route('customers.index', array_merge(request()->only('search','type'), ['shipping_area' => 'none'])) }}"
       class="btn btn-sm btn-outline-dark py-0">Show them</a>
</div>
@endif
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2" id="filterForm">
            <input type="text" name="search" class="form-control form-control-sm" style="width:220px;"
                placeholder="Search name or phone…" value="{{ request('search') }}">
            <select name="type" class="form-select form-select-sm" style="width:auto;">
                <option value="">All types</option>
                <option value="customer"          {{ request('type')=='customer'?'selected':'' }}>Customer</option>
                <option value="reseller"          {{ request('type')=='reseller'?'selected':'' }}>Reseller</option>
                <option value="selected_customer" {{ request('type')=='selected_customer'?'selected':'' }}>Selected Customer</option>
            </select>
            <select name="shipping_area" class="form-select form-select-sm" style="width:auto;">
                <option value="">Any shipping area</option>
                <option value="none" {{ request('shipping_area')=='none'?'selected':'' }}>⚠ No area set</option>
                <option value="set"  {{ request('shipping_area')=='set'?'selected':'' }}>Area set</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
            @if(request('search') || request('type') || request('shipping_area'))
                <a href="{{ route('customers.index') }}" class="btn btn-sm btn-link">Clear</a>
            @endif
        </form>
    </div>
    <div class="col-auto d-flex gap-2">
        {{-- Import/Export dropdown --}}
        @if(auth()->user()->hasPermission('customers.import'))
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-arrow-down-up me-1"></i>Import / Export
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:220px;">
                <li><h6 class="dropdown-header">Export</h6></li>
                <li>
                    <a class="dropdown-item" href="{{ route('customers.export') }}" onclick="showExport('Preparing your export file. Please wait…')">
                        <i class="bi bi-download me-2 text-success"></i>Export all as Excel
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Import</h6></li>
                <li>
                    <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#importModal">
                        <i class="bi bi-upload me-2 text-primary"></i>Import from Excel
                    </button>
                </li>
            </ul>
        </div>
        @endif
        @if(auth()->user()->isAdmin())
        {{-- Bulk delete dropdown --}}
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-danger dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-trash3 me-1"></i>Delete
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <button class="dropdown-item" id="deleteSelectedBtn" disabled onclick="confirmBulkDelete('selected')">
                        <i class="bi bi-check2-square me-2"></i>Delete selected
                        <span class="badge bg-danger ms-1" id="selectedCount" style="display:none;"></span>
                    </button>
                </li>
                <li>
                    <button class="dropdown-item text-danger" onclick="confirmBulkDelete('no_orders')">
                        <i class="bi bi-people me-2"></i>Delete all with no orders
                    </button>
                </li>
            </ul>
        </div>
        @endif
@if(auth()->user()->hasPermission('customers.create'))
        <a href="{{ route('customers.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Add Customer
        </a>
        @endif
    </div>
</div>

{{-- Import Modal --}}
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Import Customers from Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">
                    <strong>Columns (6) — Customer Import format:</strong><br>
                    <code class="small">Name · Phone · Type · Shipping Area · Address · Notes</code>
                    <span class="text-muted d-block mt-1">
                        • <strong>Type:</strong> customer / reseller / selected_customer (blank = customer).<br>
                        • <strong>Phone:</strong> Indonesian format e.g. 081234567890.<br>
                        • <strong>Shipping Area:</strong> must match an area name in the system.<br>
                        • Duplicates (same phone or name) are skipped automatically.
                    </span>
                    <div class="mt-2 d-flex gap-3">
                        <a onclick="showExport('Preparing template download…')" href="{{ route('customers.import.template') }}" class="small">
                            <i class="bi bi-download me-1"></i>Download template (.xlsx)
                        </a>
                        <a onclick="showExport('Preparing your export file. Please wait…')" href="{{ route('customers.export') }}" class="small">
                            <i class="bi bi-download me-1"></i>Export existing customers
                        </a>
                    </div>
                </div>
                <form method="POST" action="{{ route('customers.import') }}" enctype="multipart/form-data" onsubmit="const ov=document.getElementById('processingOverlay'); document.getElementById('processingMsg').textContent='Importing customers from Excel. Large files may take up to a minute. Please do not close this page.'; ov.style.display='flex';">
                    @csrf
                    <input type="file" name="file" class="form-control mb-3" accept=".xlsx" required>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-upload me-1"></i>Import
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Bulk delete hidden form --}}
<form method="POST" action="{{ route('customers.bulk-destroy') }}" id="bulkDeleteForm">
    @csrf @method('DELETE')
    <input type="hidden" name="action" id="bulkAction">
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 responsive-cards">
            <thead class="table-light">
                <tr>
@if(auth()->user()->hasPermission('customers.delete'))
                    <th style="width:36px;">
                        <input type="checkbox" id="selectAll" class="form-check-input">
                    </th>
                    @endif
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Type</th>
                    <th>Orders</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                <tr>
@if(auth()->user()->hasPermission('customers.delete'))
                    <td class="no-label">
                        <input type="checkbox" class="form-check-input customer-cb" value="{{ $customer->id }}">
                    </td>
                    @endif
                    <td class="fw-semibold" data-label="Name">
                        {{ $customer->name }}
                        @if(!$customer->default_shipping_area_id)
                            <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;" title="No shipping area set">
                                <i class="bi bi-exclamation-triangle-fill"></i> No area
                            </span>
                        @endif
                    </td>
                    <td class="text-muted small" data-label="Phone">{{ $customer->phone ?? '—' }}</td>
                    <td data-label="Type">
                        @if($customer->type === 'reseller')
                            <span class="badge" style="background:#7c3aed;">Reseller</span>
                        @elseif($customer->type === 'selected_customer')
                            <span class="badge bg-info text-dark">Selected</span>
                        @else
                            <span class="badge bg-secondary">Customer</span>
                        @endif
                    </td>
                    <td data-label="Orders">{{ $customer->orders_count }}</td>
                    <td class="cell-actions no-label">
                        <a href="{{ route('customers.show', $customer) }}" class="btn btn-sm btn-outline-primary">View</a>
                        @if(auth()->user()->hasPermission('customers.edit'))
                        <a href="{{ route('customers.edit', $customer) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No customers found</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted">{{ $customers->total() }} customer(s)</span>
            <form method="GET" action="{{ route('customers.index') }}" class="d-flex align-items-center gap-1 ms-2">
                @foreach(request()->except('per_page','page') as $k => $v)
                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                @endforeach
                <label class="small text-muted mb-0">Show:</label>
                <select name="per_page" class="form-select form-select-sm" style="width:70px;" onchange="this.form.submit()">
                    @foreach([20,50,100,200] as $n)
                        <option value="{{ $n }}" {{ $perPage==$n?'selected':'' }}>{{ $n }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div>{{ $customers->links() }}</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Select all checkbox
const selectAll = document.getElementById('selectAll');
const cbs       = () => document.querySelectorAll('.customer-cb');
const deleteBtn = document.getElementById('deleteSelectedBtn');
const countBadge = document.getElementById('selectedCount');

function updateDeleteBtn() {
    const checked = [...cbs()].filter(c => c.checked);
    deleteBtn.disabled = checked.length === 0;
    if (checked.length > 0) {
        countBadge.style.display = '';
        countBadge.textContent = checked.length;
    } else {
        countBadge.style.display = 'none';
    }
}

selectAll.addEventListener('change', function () {
    cbs().forEach(cb => cb.checked = this.checked);
    updateDeleteBtn();
});

document.addEventListener('change', function (e) {
    if (e.target.classList.contains('customer-cb')) updateDeleteBtn();
});

function confirmBulkDelete(action) {
    const form   = document.getElementById('bulkDeleteForm');
    const actionInput = document.getElementById('bulkAction');

    if (action === 'selected') {
        const checked = [...cbs()].filter(c => c.checked);
        if (checked.length === 0) return;
        if (!confirm(`Delete ${checked.length} selected customer(s)? This also deletes all their orders. This cannot be undone.`)) return;

        // Append customer IDs to form
        document.querySelectorAll('.bulk-id').forEach(e => e.remove());
        checked.forEach(cb => {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'customer_ids[]';
            inp.value = cb.value;
            inp.className = 'bulk-id';
            form.appendChild(inp);
        });
        actionInput.value = 'selected';
    } else {
        if (!confirm('Delete ALL customers with no orders? This cannot be undone.')) return;
        actionInput.value = 'no_orders';
    }

    form.submit();
}
</script>
@endpush