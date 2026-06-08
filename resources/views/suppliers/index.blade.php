@extends('layouts.app')
@section('title', 'Suppliers')
@section('page-title', 'Suppliers')

@section('content')
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-sm" style="width:240px;"
                placeholder="Search name or contact…" value="{{ request('search') }}">
            <button class="btn btn-sm btn-outline-secondary">Search</button>
            @if(request('search'))
                <a href="{{ route('suppliers.index') }}" class="btn btn-sm btn-link">Clear</a>
            @endif
        </form>
    </div>
    <div class="col-auto d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-danger" id="bulkDeleteBtn" style="display:none;" onclick="bulkDelete()">
            <i class="bi bi-trash me-1"></i>Delete Selected (<span id="selectedCount">0</span>)
        </button>
        <a href="{{ route('suppliers.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Add Supplier
        </a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                    <th>Name</th><th>Contact</th><th>Phone</th><th>Country</th><th>Products</th><th>POs</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($suppliers as $supplier)
                <tr>
                    <td><input type="checkbox" class="form-check-input row-check" value="{{ $supplier->id }}"></td>
                    <td class="fw-semibold">{{ $supplier->name }}</td>
                    <td class="text-muted small">{{ $supplier->contact_person ?? '—' }}</td>
                    <td class="text-muted small">{{ $supplier->phone ?? '—' }}</td>
                    <td class="small">{{ $supplier->country ?? '—' }}</td>
                    <td>{{ $supplier->products_count }}</td>
                    <td>{{ $supplier->purchase_orders_count }}</td>
                    <td>
                        @if($supplier->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('suppliers.show', $supplier) }}" class="btn btn-sm btn-outline-primary">View</a>
                        <a href="{{ route('suppliers.edit', $supplier) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        <form method="POST" action="{{ route('suppliers.destroy', $supplier) }}" class="d-inline"
                            onsubmit="return confirm('Delete {{ $supplier->name }}? Products linked to this supplier will be unlinked.')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">×</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No suppliers yet. <a href="{{ route('suppliers.create') }}">Add one</a></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted">{{ $suppliers->total() }} supplier(s)</span>
            <form method="GET" action="{{ route('suppliers.index') }}" class="d-flex align-items-center gap-1 ms-2">
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
        <div>{{ $suppliers->links() }}</div>
    </div>
</div>
{{-- Bulk Delete Form --}}
<form id="bulkDeleteForm" method="POST" action="{{ route('suppliers.bulk-destroy') }}" style="display:none;">
    @csrf @method('DELETE')
    <div id="bulkIds"></div>
</form>

@push('scripts')
<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
    updateBulkBtn();
});
document.addEventListener('change', e => {
    if (e.target.classList.contains('row-check')) updateBulkBtn();
});
function updateBulkBtn() {
    const checked = document.querySelectorAll('.row-check:checked').length;
    const btn = document.getElementById('bulkDeleteBtn');
    if (btn) { btn.style.display = checked > 0 ? '' : 'none'; }
    const cnt = document.getElementById('selectedCount');
    if (cnt) cnt.textContent = checked;
}
function bulkDelete() {
    const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
    if (!ids.length) return;
    if (!confirm(`Delete ${ids.length} supplier(s)? Products linked will be unlinked.`)) return;
    const container = document.getElementById('bulkIds');
    container.innerHTML = ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('');
    document.getElementById('bulkDeleteForm').submit();
}
</script>
@endpush
@endsection