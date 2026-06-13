@extends('layouts.app')
@section('title', 'Promo Rules')
@section('page-title', 'Promo Rules')

@section('content')

{{-- Toolbar --}}
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center justify-content-end">

    @if(auth()->user()->hasPermission('promos.edit'))
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-danger dropdown-toggle" data-bs-toggle="dropdown">
            <i class="bi bi-trash3 me-1"></i>Delete
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li>
                <button class="dropdown-item" id="deleteSelectedBtn" disabled onclick="bulkDelete('selected')">
                    <i class="bi bi-check2-square me-2"></i>Delete selected (<span id="selectedCount">0</span>)
                </button>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <button class="dropdown-item text-danger" onclick="bulkDelete('all')">
                    <i class="bi bi-trash-fill me-2"></i>Delete all promo rules
                </button>
            </li>
        </ul>
    </div>

    <a href="{{ route('promos.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add Rule
    </a>
    @endif
</div>

{{-- Table --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 responsive-cards">
            <thead class="table-light">
                <tr>
                    @if(auth()->user()->hasPermission('promos.edit'))
                    <th style="width:36px;">
                        <input type="checkbox" id="selectAll" class="form-check-input">
                    </th>
                    @endif
                    <th>Name</th>
                    <th>Trip</th>
                    <th>Min Items</th>
                    <th>Discount/Item</th>
                    <th>Flat Discount</th>
                    <th>Shipping Subsidy</th>
                    <th>Customer Types</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($promos as $promo)
                <tr>
                    @if(auth()->user()->hasPermission('promos.edit'))
                    <td class="no-label">
                        <input type="checkbox" class="form-check-input promo-cb" value="{{ $promo->id }}">
                    </td>
                    @endif
                    <td class="fw-semibold" data-label="Name">{{ $promo->name }}</td>
                    <td class="text-muted small" data-label="Trip">{{ $promo->trip?->name ?? 'All trips' }}</td>
                    <td data-label="Min Items">{{ $promo->min_items }}</td>
                    <td data-label="Discount/Item">
                        @if($promo->discount_per_item > 0)
                            <span class="text-success fw-semibold">Rp {{ number_format($promo->discount_per_item, 0, ',', '.') }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Flat Discount">
                        @if($promo->discount_flat > 0)
                            <span class="text-success fw-semibold">Rp {{ number_format($promo->discount_flat, 0, ',', '.') }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Shipping Subsidy">
                        @if($promo->max_shipping_subsidy > 0)
                            <span class="fw-semibold text-secondary">Rp {{ number_format($promo->max_shipping_subsidy, 0, ',', '.') }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Customer Types">
                        @if($promo->eligible_customer_types)
                            @foreach($promo->eligible_customer_types as $t)
                            @php
                                $badgeClass = match($t) {
                                    'reseller'          => 'bg-primary',
                                    'selected_customer' => 'bg-warning text-dark',
                                    'customer'          => 'bg-success',
                                    default             => 'bg-light text-dark border',
                                };
                            @endphp
                                <span class="badge {{ $badgeClass }}" style="font-size:.7rem;">{{ $t }}</span>
                            @endforeach
                        @else
                            <span class="text-muted small">All</span>
                        @endif
                    </td>
                    <td data-label="Status">
                        <span class="badge {{ $promo->is_active ? 'bg-success' : 'bg-secondary' }}">
                            {{ $promo->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="cell-actions no-label">
                        @if(auth()->user()->hasPermission('promos.edit'))
                        <a href="{{ route('promos.edit', $promo) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ auth()->user()->hasPermission('promos.edit') ? 10 : 9 }}"
                        class="text-center text-muted py-4">No promo rules yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
        <span class="small text-muted">{{ $promos->total() }} rule(s)</span>
        <div>{{ $promos->links() }}</div>
    </div>
</div>

@if(auth()->user()->hasPermission('promos.edit'))
<form id="bulkDeleteForm" method="POST" action="{{ route('promos.bulk-destroy') }}" style="display:none;">
    @csrf @method('DELETE')
    <input type="hidden" name="delete_all" id="deleteAllFlag" value="0">
    <div id="bulkIds"></div>
</form>
@endif

@push('scripts')
<script>
const selectAll = document.getElementById('selectAll');

selectAll?.addEventListener('change', function () {
    document.querySelectorAll('.promo-cb').forEach(cb => cb.checked = this.checked);
    updateToolbar();
});

document.addEventListener('change', e => {
    if (e.target.classList.contains('promo-cb')) updateToolbar();
});

function updateToolbar() {
    const count = document.querySelectorAll('.promo-cb:checked').length;
    document.getElementById('selectedCount').textContent = count;
    const btn = document.getElementById('deleteSelectedBtn');
    if (btn) btn.disabled = count === 0;
}

function bulkDelete(mode) {
    if (mode === 'selected') {
        const ids = [...document.querySelectorAll('.promo-cb:checked')].map(cb => cb.value);
        if (!ids.length) return;
        if (!confirm(`Delete ${ids.length} promo rule(s)? This cannot be undone.`)) return;
        document.getElementById('deleteAllFlag').value = '0';
        const container = document.getElementById('bulkIds');
        container.innerHTML = ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('');
    } else {
        const total = {{ $promos->total() }};
        if (!confirm(`Delete ALL ${total} promo rules? This cannot be undone.`)) return;
        document.getElementById('deleteAllFlag').value = '1';
        document.getElementById('bulkIds').innerHTML = '';
    }
    document.getElementById('bulkDeleteForm').submit();
}
</script>
@endpush
@endsection