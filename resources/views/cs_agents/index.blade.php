@extends('layouts.app')
@section('title', 'CS Agents')
@section('page-title', 'CS Agents')

@push('styles')
<style>
.modal.fade:not(.show){display:none!important;}
</style>
@endpush

@section('content')
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <form method="GET" class="d-flex gap-2 w-100" style="max-width:420px;">
        <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="Search CS name or handle…">
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
        @if(request('search'))
            <a href="{{ route('cs-agents.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
        @endif
    </form>
    <button class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-lg me-1"></i>Add CS Agent
    </button>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>IG/WA Handle</th>
                    <th class="text-center">Orders</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($agents as $agent)
                <tr>
                    <td class="fw-semibold">{{ $agent->name }}</td>
                    <td class="text-muted">{{ $agent->handle ?: '—' }}</td>
                    <td class="text-center">{{ $agent->orders_count }}</td>
                    <td class="text-center">
                        @if($agent->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal-{{ $agent->id }}">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" action="{{ route('cs-agents.destroy', $agent) }}" class="d-inline" onsubmit="return confirm('Delete this CS agent? Their name will be removed from {{ $agent->orders_count }} order(s), but the orders stay.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No CS agents yet. Add one to get started.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $agents->links() }}</div>

{{-- Add Modal --}}
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('cs-agents.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add CS Agent</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Rina">
                        <div class="form-text">The name that appears in the order form and IG/WA export column.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">IG/WA Handle</label>
                        <input type="text" name="handle" class="form-control" placeholder="@rina_cs (optional)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" id="addActive" checked>
                        <label class="form-check-label" for="addActive">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Agent</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Modals --}}
@foreach($agents as $agent)
<div class="modal fade" id="editModal-{{ $agent->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('cs-agents.update', $agent) }}">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit CS Agent</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required value="{{ $agent->name }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">IG/WA Handle</label>
                        <input type="text" name="handle" class="form-control" value="{{ $agent->handle }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2">{{ $agent->notes }}</textarea>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" id="editActive-{{ $agent->id }}" {{ $agent->is_active ? 'checked' : '' }}>
                        <label class="form-check-label" for="editActive-{{ $agent->id }}">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach
@endsection