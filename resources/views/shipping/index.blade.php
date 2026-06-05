@extends('layouts.app')
@section('title', 'Shipping Areas')
@section('page-title', 'Shipping Areas')

@section('content')
{{-- Import / Export / Template toolbar --}}
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-sm" style="width:220px;"
                placeholder="Search area or province…" value="{{ request('search') }}">
            <button class="btn btn-sm btn-outline-secondary">Search</button>
            @if(request('search'))
                <a href="{{ route('shipping.index') }}" class="btn btn-sm btn-link">Clear</a>
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
                    <a class="dropdown-item" href="{{ route('shipping.export') }}">
                        <i class="bi bi-download me-2 text-success"></i>Export all as Excel
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Import</h6></li>
                <li>
                    <a class="dropdown-item" href="{{ route('shipping.template') }}">
                        <i class="bi bi-file-earmark-spreadsheet me-2 text-secondary"></i>Download template (.xlsx)
                    </a>
                </li>
                <li>
                    <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#importModal">
                        <i class="bi bi-upload me-2 text-primary"></i>Import from Excel
                    </button>
                </li>
            </ul>
        </div>
        <a href="{{ route('shipping.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add Area
        </a>
    </div>
</div>

{{-- Shipping calculation reference --}}
<div class="alert alert-info py-2 small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Shipping weight rule:</strong>
    ≤ 1,350g → 1 kg &nbsp;|&nbsp; ≤ 2,350g → 2 kg &nbsp;|&nbsp; ≤ 3,350g → 3 kg &nbsp;|&nbsp; and so on.
    Formula: <code>ceil((grams − 350) / 1000)</code>, minimum 1 kg.
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Area / City</th>
                    <th>Province</th>
                    <th>Price / kg</th>
                    <th>Sample: 500g</th>
                    <th>Sample: 1.5kg</th>
                    <th>Sample: 3kg</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($areas as $area)
                <tr>
                    <td class="fw-semibold">{{ $area->name }}</td>
                    <td class="text-muted small">{{ $area->province ?? '—' }}</td>
                    <td>Rp {{ number_format($area->price_per_kg, 0, ',', '.') }}</td>
                    <td class="small text-muted">Rp {{ number_format($area->calcShippingFee(500), 0, ',', '.') }}</td>
                    <td class="small text-muted">Rp {{ number_format($area->calcShippingFee(1500), 0, ',', '.') }}</td>
                    <td class="small text-muted">Rp {{ number_format($area->calcShippingFee(3000), 0, ',', '.') }}</td>
                    <td>
                        @if($area->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('shipping.edit', $area) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        <form method="POST" action="{{ route('shipping.destroy', $area) }}" class="d-inline"
                            onsubmit="return confirm('Delete {{ $area->name }}?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">×</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        No shipping areas yet.
                        <a href="{{ route('shipping.template') }}">Download template (.xlsx)</a> to import in bulk.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $areas->links() }}</div>
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
                    <div class="mb-3">
                        <input type="file" name="file" class="form-control" accept=".xlsx" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
