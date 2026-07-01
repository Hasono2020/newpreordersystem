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
        <strong>Tips:</strong> Product codes must exist in the selected trip. Color and Size must exactly match the variant names in the system. Fix all errors listed above, then re-import the file.
    </div>
</div>
@endif

@if($noAreaCount > 0 && request('shipping_area') !== 'none')
<div class="alert alert-warning d-flex justify-content-between align-items-center py-2 mb-3">
    <div class="small">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>{{ $noAreaCount }}</strong> order(s){{ request('trip_id') ? ' in this trip' : '' }} have no shipping area set — shipping can't be calculated until an area is assigned.
    </div>
    <a href="{{ route('orders.index', array_merge(request()->only('search','trip_id','payment_status','created_by'), ['shipping_area' => 'none'])) }}"
       class="btn btn-sm btn-outline-dark py-0">Show them</a>
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
            <select name="shipping_area" class="form-select form-select-sm" style="width:auto;">
                <option value="">Any area</option>
                <option value="none" {{ request('shipping_area')=='none'?'selected':'' }}>⚠ No area set</option>
                <option value="set"  {{ request('shipping_area')=='set'?'selected':'' }}>Area set</option>
            </select>
            @if(!auth()->user()->isOwnDataOnly())
            <select name="created_by" class="form-select form-select-sm" style="width:auto;">
                <option value="">All Staff</option>
                @foreach($staffList as $staff)
                    <option value="{{ $staff->id }}" {{ request('created_by') == $staff->id ? 'selected' : '' }}>
                        {{ $staff->name }}
                    </option>
                @endforeach
            </select>
            @endif
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
            @if(request()->anyFilled(['search','trip_id','payment_status','created_by','shipping_area']))
                <a href="{{ route('orders.index') }}" class="btn btn-sm btn-link">Clear</a>
            @endif
        </form>
    </div>
    <div class="col-auto d-flex gap-2">
        @if(auth()->user()->hasPermission('orders.delete'))
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-danger dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-trash3 me-1"></i>Delete
            </button>
            <ul class="dropdown-menu">
                <li>
                    <button class="dropdown-item" id="deleteSelectedBtn" disabled onclick="confirmBulkDelete('selected')">
                        <i class="bi bi-check2-square me-2"></i>Delete selected
                        <span class="badge bg-danger ms-1" id="selectedCount" style="display:none;"></span>
                    </button>
                </li>
                <li>
                    <button class="dropdown-item text-danger" onclick="confirmBulkDelete('unpaid')">
                        <i class="bi bi-x-circle me-2"></i>Delete all unpaid{{ request('trip_id') ? ' (this trip)' : '' }}
                    </button>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button class="dropdown-item text-danger" onclick="confirmBulkDelete('trip')">
                        <i class="bi bi-collection me-2"></i>Delete ALL orders in this trip
                    </button>
                </li>
            </ul>
        </div>
        @endif
        @if(auth()->user()->hasPermission('orders.export') || auth()->user()->hasPermission('orders.import'))
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-arrow-down-up me-1"></i>
                @if(auth()->user()->hasPermission('orders.import')) Import / Export @else Export @endif
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="min-width:240px;">
                <li><h6 class="dropdown-header">Export</h6></li>
                <li>
                    <a onclick="showExport('Preparing your export file. Please wait…')" class="dropdown-item" href="{{ route('orders.export', request()->only('trip_id')) }}">
                        <i class="bi bi-download me-2 text-success"></i>Export orders as Excel
                    </a>
                </li>
                <li>
                    <a onclick="showExport('Preparing your export file. Please wait…')" class="dropdown-item" href="{{ route('orders.items.export', request()->only('trip_id')) }}">
                        <i class="bi bi-download me-2 text-info"></i>Export order items as Excel
                    </a>
                </li>
                @if(auth()->user()->hasPermission('orders.import'))
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Import</h6></li>
                <li>
                    <a class="dropdown-item" href="{{ route('orders.import.template') }}" onclick="showExport('Preparing template download…')">
                        <i class="bi bi-file-earmark-spreadsheet me-2 text-secondary"></i>Download template (.xlsx)
                    </a>
                </li>
                <li>
                    <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#importOrderModal">
                        <i class="bi bi-upload me-2 text-primary"></i>Import orders from Excel
                    </button>
                </li>
                @endif
            </ul>
        </div>
        @endif
@if(auth()->user()->hasPermission('orders.create'))
        <a href="{{ route('orders.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Order</a>
        @endif
    </div>
</div>

{{-- Import Order Modal --}}
<div class="modal fade" id="importOrderModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Import Orders from Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2 px-3 small mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <strong>Import order affects FIFO priority.</strong><br>
                    If <em>Ordered At</em> column is blank, each row gets a timestamp based on its row position — row 2 is earlier than row 6.<br>
                    <strong>If importing multiple files: import earliest orders first, latest orders last.</strong>
                </div>
                <div class="alert alert-light border small mb-3">
                    <strong>Columns (14) — Order Import format:</strong><br>
                    <code class="small">DIBUAT OLEH · NO · NAMA · IG/WA · NO HP · KOTA · KODE · WARNA · SIZE · HARGA SATUAN · DP · TGL DP · AN · KET</code>
                    <span class="text-muted d-block mt-1">
                        • <strong>Each row = 1 order + 1 item.</strong> Row order = FIFO priority (row 1 gets stock first).<br>
                        • <strong>DIBUAT OLEH</strong> is set automatically to the logged-in user — ignored on import.<br>
                        • <strong>IG/WA</strong> = CS agent who handled the livechat — <strong>required</strong>, must match an existing CS agent name.<br>
                        • <strong>NO HP</strong> = customer phone number.<br>
                        • <strong>KODE</strong> must exist in the selected trip. <strong>WARNA/SIZE</strong> must match exactly.<br>
                        • Leave <strong>HARGA SATUAN</strong> blank to use system product price.<br>
                        • <strong>AN</strong> = Atas Nama / order notes.<br>
                        • All rows are validated before import — any error blocks the entire file.
                    </span>
                    <a onclick="showExport('Preparing template download…')" href="{{ route('orders.import.template') }}" class="small mt-1 d-inline-block">
                        <i class="bi bi-download me-1"></i>Download template (.xlsx)
                    </a>
                </div>
                <form method="POST" action="{{ route('orders.import') }}" enctype="multipart/form-data" onsubmit="const ov=document.getElementById('processingOverlay'); document.getElementById('processingMsg').textContent='Uploading file…'; ov.style.display='flex'; setTimeout(()=>{ov.style.display='none';}, 4000);">
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

{{-- Recent Imports status panel (auto-refreshes while any import is queued/processing) --}}
<div class="card mb-3" id="recentImportsCard" style="display:none;">
    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
        <span class="small fw-semibold"><i class="bi bi-clock-history me-1"></i>Recent Imports</span>
        <button class="btn btn-sm btn-link p-0" onclick="hideImportsPanel()">Hide</button>
    </div>
    <div class="card-body py-2" id="recentImportsBody">
        <div class="text-muted small">Loading…</div>
    </div>
</div>

@if(auth()->user()->hasPermission('orders.delete'))
<form method="POST" action="{{ route('orders.bulk-destroy') }}" id="bulkDeleteForm">
    @csrf
    <input type="hidden" name="action" id="bulkAction">
    <input type="hidden" name="trip_id" value="{{ request('trip_id') }}">
</form>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 responsive-cards">
            <thead class="table-light">
                <tr>
                    @if(auth()->user()->isAdmin())
@if(auth()->user()->hasPermission('orders.delete'))
                    <th style="width:36px;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                    @endif
                    @endif
                    <th>Order #</th><th>Customer</th><th>Trip</th><th>Subtotal</th><th>Discount</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Created By</th><th>Created Time</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                <tr>
                    @if(auth()->user()->isAdmin())
@if(auth()->user()->hasPermission('orders.delete'))
                    <td class="no-label"><input type="checkbox" name="order_ids[]" value="{{ $order->id }}" class="form-check-input order-checkbox" form="bulkDeleteForm"></td>
                    @endif
                    @endif
                    <td class="font-monospace small" data-label="Order #">{{ $order->order_number }}</td>
                    <td data-label="Customer">
                        <div class="text-end">
                            <div class="fw-semibold">{{ $order->customer->name }}</div>
                            <div class="text-muted" style="font-size:.72rem;">{{ $order->customer->type_label }}</div>
                        </div>
                    </td>
                    <td class="small text-muted" data-label="Trip">
                        {{ $order->trip->name }}
                        @if(!$order->shipping_area_id)
                            <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;" title="No shipping area set — shipping not calculated">
                                <i class="bi bi-exclamation-triangle-fill"></i> No area
                            </span>
                        @endif
                    </td>
                    <td class="small" data-label="Subtotal">Rp {{ number_format($order->subtotal, 0, ',', '.') }}</td>
                    <td class="small text-success" data-label="Discount">
                        @if($order->discount_amount > 0) -Rp {{ number_format($order->discount_amount, 0, ',', '.') }} @else — @endif
                    </td>
                    <td class="fw-semibold" data-label="Total">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td>
                    <td class="small text-success" data-label="Paid">Rp {{ number_format($order->deposit_paid, 0, ',', '.') }}</td>
                    <td class="small {{ $order->remaining_balance > 0 ? 'text-danger' : 'text-success' }}" data-label="Balance">
                        Rp {{ number_format($order->remaining_balance, 0, ',', '.') }}
                    </td>
                    <td data-label="Status">{!! $order->payment_status_badge !!}</td>
                    <td data-label="Created By" class="small text-muted">{{ $order->createdBy->name ?? '—' }}</td>
                    <td data-label="Created Time" class="small text-muted text-nowrap">{{ $order->created_at->format('d M Y, H:i') }}</td>
                    <td class="cell-actions no-label">
                        <a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-outline-primary">View</a>
                        @if(auth()->user()->hasPermission('orders.edit') && (auth()->user()->isAdmin() || auth()->user()->role !== 'staff' || $order->created_by === auth()->id()))
                        <a href="{{ route('orders.edit', $order) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        @endif
                        <a href="{{ route('orders.combined-invoice', $order->customer_id) }}?trip_id={{ $order->trip_id }}"
                           target="_blank" class="btn btn-sm btn-outline-dark"
                           title="Print combined invoice — all this customer's orders in this trip">
                            <i class="bi bi-printer me-1"></i>Print
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="{{ auth()->user()->isAdmin() ? 12 : 11 }}" class="text-center text-muted py-4">No orders found</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted">{{ $orders->total() }} order(s)</span>
            <form method="GET" action="{{ route('orders.index') }}" class="d-flex align-items-center gap-1 ms-2">
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
        <div>{{ $orders->links() }}</div>
    </div>
</div>

@if(auth()->user()->isAdmin())
<script>
const selectAll   = document.getElementById('selectAll');
const countBadge  = document.getElementById('selectedCount');
const deleteBtn   = document.getElementById('deleteSelectedBtn');

function updateCount() {
    const checked = document.querySelectorAll('.order-checkbox:checked').length;
    if (deleteBtn) { deleteBtn.disabled = checked === 0; }
    if (countBadge) { countBadge.style.display = checked > 0 ? 'inline-block' : 'none'; countBadge.textContent = checked; }
}

selectAll?.addEventListener('change', () => {
    document.querySelectorAll('.order-checkbox').forEach(c => c.checked = selectAll.checked);
    updateCount();
});
document.querySelectorAll('.order-checkbox').forEach(c => c.addEventListener('change', updateCount));

function confirmBulkDelete(action) {
    const form = document.getElementById('bulkDeleteForm');
    document.getElementById('bulkAction').value = action;
    const tripId = '{{ request("trip_id") }}';
    const msgs = {
        selected: `Delete ${document.querySelectorAll('.order-checkbox:checked').length} selected order(s)? This cannot be undone.`,
        unpaid:   'Delete ALL unpaid orders{{ request("trip_id") ? " for this trip" : "" }}? This cannot be undone.',
        trip:     tripId
            ? 'Delete ALL orders in this trip? This cannot be undone.'
            : 'No trip selected. Please filter by a trip first, then use this option.',
    };
    if (action === 'trip' && !tripId) { alert(msgs.trip); return; }
    if (confirm(msgs[action] || 'Delete?')) form.submit();
}
</script>
@endif
@endsection

@push('scripts')
@if(session('import_errors'))
<script>
    // Auto-reopen import modal so user sees errors in context
    document.addEventListener('DOMContentLoaded', function () {
        var modal = new bootstrap.Modal(document.getElementById('importOrderModal'));
        modal.show();
    });
</script>
@endif

<script>
// ── Recent Imports panel: poll while any import is queued/processing ──
let importErrorsCache = {}; // job.id -> row_errors[], avoids embedding raw text in HTML attributes

function showImportErrors(jobId) {
    const errors = importErrorsCache[jobId] || [];
    alert(errors.length ? errors.join('\n') : 'No error details available.');
}

function renderImportRow(job) {
    importErrorsCache[job.id] = job.row_errors || [];

    const badge = {
        queued:     '<span class="badge bg-secondary">Queued</span>',
        processing: '<span class="badge bg-info text-dark">Processing…</span>',
        done:       '<span class="badge bg-success">Done</span>',
        failed:     '<span class="badge bg-danger">Failed</span>',
    }[job.status] || job.status;

    let detail = '';
    if (job.status === 'done') {
        detail = `Imported ${job.imported_count ?? 0} order(s)` + (job.skipped_count ? `, ${job.skipped_count} skipped` : '') + '.';
    } else if (job.status === 'failed') {
        if (job.row_errors && job.row_errors.length) {
            detail = `${job.row_errors.length} row error(s) — fix the file and re-import. ` +
                      `<button class="btn btn-sm btn-link p-0 align-baseline" onclick="showImportErrors(${job.id})">View errors</button>`;
        } else if (job.error_message) {
            detail = job.error_message;
        }
    } else if (job.status === 'processing' && job.total_rows) {
        detail = `Processing ${job.total_rows} row(s)…`;
    }

    return `<div class="d-flex justify-content-between align-items-start py-1 small border-bottom">
        <div>
            <span class="font-monospace">${job.original_filename}</span><br>
            <span class="text-muted">${detail}</span>
        </div>
        <div class="text-end ms-2">${badge}</div>
    </div>`;
}

let importPollTimer = null;
let latestSeenJobId = 0; // tracks the newest job.id currently shown, for the Hide-persistence logic

function hideImportsPanel() {
    // Remember "hidden up to this point" — stays hidden across refresh until a NEWER import appears.
    localStorage.setItem('importsPanelHiddenUntilId', String(latestSeenJobId));
    document.getElementById('recentImportsCard').style.display = 'none';
}

function pollImportStatus() {
    fetch('{{ route("orders.import.status") }}')
        .then(r => r.json())
        .then(jobs => {
            const card = document.getElementById('recentImportsCard');
            const body = document.getElementById('recentImportsBody');
            if (!jobs.length) { card.style.display = 'none'; return; }

            latestSeenJobId = Math.max(...jobs.map(j => j.id));

            const stillActive = jobs.some(j => j.status === 'queued' || j.status === 'processing');
            const hiddenUntilId = parseInt(localStorage.getItem('importsPanelHiddenUntilId') || '0', 10);
            // Active imports always show (so you don't miss a failure). Otherwise, respect a prior
            // Hide click unless a newer import has happened since then.
            const shouldShow = stillActive || latestSeenJobId > hiddenUntilId;

            body.innerHTML = jobs.map(renderImportRow).join('');
            card.style.display = shouldShow ? 'block' : 'none';

            if (stillActive && !importPollTimer) {
                importPollTimer = setInterval(pollImportStatus, 4000);
            } else if (!stillActive && importPollTimer) {
                clearInterval(importPollTimer);
                importPollTimer = null;
            }
        })
        .catch(() => {});
}
document.addEventListener('DOMContentLoaded', pollImportStatus);
</script>
@endpush