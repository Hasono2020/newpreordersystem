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
    <div class="col-auto">
        <a href="{{ route('suppliers.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Add Supplier
        </a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Contact</th><th>Phone</th><th>Country</th><th>Products</th><th>POs</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($suppliers as $supplier)
                <tr>
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
                <tr><td colspan="8" class="text-center text-muted py-4">No suppliers yet. <a href="{{ route('suppliers.create') }}">Add one</a></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $suppliers->links() }}</div>
</div>
@endsection
