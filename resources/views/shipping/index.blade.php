@extends('layouts.app')
@section('title', 'Shipping Areas')
@section('page-title', 'Shipping Areas')

@section('content')

{{-- Toolbar --}}
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <form class="d-flex gap-2 me-auto" method="GET" action="{{ route('shipping.index') }}">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
        <input type="text" name="search" class="form-control form-control-sm" style="max-width:240px;"
            placeholder="Search area or province…" value="{{ request('search') }}">
        <button class="btn btn-sm btn-outline-secondary">Search</button>
        @if(request('search'))
            <a href="{{ route('shipping.index', ['per_page' => $perPage]) }}" class="btn btn-sm btn-link">Clear</a>
        @endif
    </form>

    @if(auth()->user()->isAdmin())
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-danger dropdown-toggle" data-bs-toggle="dropdown">
            <i class="bi bi-trash3 me-1"></i>Delete
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><button class="dropdown-item" id="deleteSelectedBtn" disabled onclick="bulkDelete()">
                <i class="bi bi-check2-square me-2"></i>Delete selected (<span id="selectedCount">0</span>)
            </button></li>
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item text-danger" onclick="deleteAll()">
                <i class="bi bi-trash-fill me-2"></i>Delete all areas
            </button></li>
        </ul>
    </div>
    @endif

    @if(auth()->user()->isAdmin() || auth()->user()->hasPermission('shipping.import'))
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
            <i class="bi bi-arrow-down-up me-1"></i>
            @if(auth()->user()->hasPermission('shipping.import')) Import / Export @else Export @endif
        </button>
        <ul class="dropdown-menu dropdown-menu-end" style="min-width:240px;">
            <li><h6 class="dropdown-header">Export</h6></li>
            <li><a class="dropdown-item" href="{{ route('shipping.export') }}"><i class="bi bi-download me-2 text-success"></i>Export all as Excel</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><h6 class="dropdown-header">Import</h6></li>
            <li><a class="dropdown-item" href="{{ route('shipping.template') }}"><i class="bi bi-file-earmark-spreadsheet me-2 text-secondary"></i>Download template (.xlsx)</a></li>
            <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload me-2 text-primary"></i>Import from Excel</button></li>
        </ul>
    </div>
    @endif

@if(auth()->user()->hasPermission('shipping.create'))
    <a href="{{ route('shipping.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add Area
    </a>
    @endif
</div>

{{-- Info --}}
<div class="alert alert-info py-2 small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Shipping formula:</strong> ≤1,350g = 1kg · ≤2,350g = 2kg · formula: <code>ceil((grams−350)/1000)</code>, min 1kg.
</div>

{{-- Table --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 responsive-cards">
            <thead class="table-light">
                <tr>
                    @if(auth()->user()->isAdmin())
                    <th style="width:36px;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                    @endif
                    <th>Area / City</th>
                    <th>Province</th>
                    <th>Price / kg</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($areas as $area)
                <tr>
                    @if(auth()->user()->isAdmin())
                    <td class="no-label"><input type="checkbox" class="form-check-input row-check" value="{{ $area->id }}"></td>
                    @endif
                    <td class="fw-semibold" data-label="Area / City">{{ $area->name }}</td>
                    <td class="text-muted small" data-label="Province">{{ $area->province ?? '—' }}</td>
                    <td data-label="Price / kg">Rp {{ number_format($area->price_per_kg, 0, ',', '.') }}</td>
                    <td data-label="Status">
                        <span class="badge {{ $area->is_active ? 'bg-success' : 'bg-secondary' }}">
                            {{ $area->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="cell-actions no-label">
                        <a href="{{ route('shipping.show', $area) }}" class="btn btn-sm btn-outline-primary">View</a>
                        @if(auth()->user()->hasPermission('shipping.edit'))
                        <a href="{{ route('shipping.edit', $area) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ auth()->user()->isAdmin() ? 6 : 5 }}" class="text-center text-muted py-4">
                        No shipping areas yet. <a href="{{ route('shipping.template') }}">Download template</a> to import in bulk.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted">{{ $areas->total() }} area(s)</span>
            <form method="GET" action="{{ route('shipping.index') }}" class="d-flex align-items-center gap-1 ms-2">
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
        <div>{{ $areas->links() }}</div>
    </div>
</div>

{{-- Import Modal --}}
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('shipping.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Import Shipping Areas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border small mb-3">
                        <strong>Excel columns:</strong> name · province · price_per_kg · is_active · notes<br>
                        <span class="text-muted">Existing areas (matched by name) will be updated. New areas will be created.</span><br>
                        <a href="{{ route('shipping.template') }}" class="small mt-1 d-inline-block">
                            <i class="bi bi-download me-1"></i>Download template (.xlsx)
                        </a>
                    </div>
                    <input type="file" name="file" class="form-control" accept=".xlsx" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>
@if(auth()->user()->isAdmin())
<form id="bulkDeleteForm" method="POST" action="{{ route('shipping.bulk-destroy') }}" style="display:none;">
    @csrf @method('DELETE')
    <input type="hidden" name="delete_all" id="deleteAllFlag" value="0">
    <div id="bulkIds"></div>
</form>
@endif

@push('scripts')
<script>
// Select all checkbox
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
    updateBulkBtn();
});
document.addEventListener('change', e => {
    if (e.target.classList.contains('row-check')) updateBulkBtn();
});
function updateBulkBtn() {
    const checked = document.querySelectorAll('.row-check:checked').length;
    const btn = document.getElementById('deleteSelectedBtn');
    if (btn) btn.disabled = checked === 0;
    const cnt = document.getElementById('selectedCount');
    if (cnt) cnt.textContent = checked;
}
function bulkDelete() {
    const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
    if (!ids.length) return;
    if (!confirm(`Delete ${ids.length} shipping area(s)? This cannot be undone.`)) return;
    document.getElementById('deleteAllFlag').value = '0';
    const container = document.getElementById('bulkIds');
    container.innerHTML = ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('');
    document.getElementById('bulkDeleteForm').submit();
}
function deleteAll() {
    const total = {{ $areas->total() }};
    if (!confirm(`⚠️ DELETE ALL ${total} shipping areas?\n\nThis will remove every single shipping area and cannot be undone.\n\nType OK to confirm.`)) return;
    document.getElementById('deleteAllFlag').value = '1';
    document.getElementById('bulkIds').innerHTML = '';
    document.getElementById('bulkDeleteForm').submit();
}
</script>
@endpush
@endsection