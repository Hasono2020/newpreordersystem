@extends('layouts.app')
@section('title', 'Staff Accounts')
@section('page-title', 'Staff Accounts')

@section('content')

<div class="row g-3">
    {{-- Add new account form --}}
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">
                <i class="bi bi-person-plus me-2"></i>Add Account
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('staff.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                            value="{{ old('name') }}" required placeholder="e.g. Siti Rahma">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                            value="{{ old('email') }}" required placeholder="email@example.com">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select">
                            <option value="staff" {{ old('role') == 'staff' ? 'selected' : '' }}>Staff</option>
                            <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Admin</option>
                        </select>
                        <div class="form-text">Admin can manage staff accounts and access all settings.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                            required minlength="8" placeholder="Min. 8 characters">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" name="password_confirmation" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-person-plus me-1"></i>Create Account
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Accounts list --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">
                <i class="bi bi-people me-2"></i>All Accounts ({{ $staff->count() }})
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach($staff as $user)
                        <tr>
                            <td>
                                <div class="fw-semibold">
                                    {{ $user->name }}
                                    @if($user->id === auth()->id())
                                        <span class="badge bg-light text-secondary border ms-1" style="font-size:.65rem;">You</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-muted small">{{ $user->email }}</td>
                            <td>
                                @if($user->role === 'admin')
                                    <span class="badge bg-primary">Admin</span>
                                @else
                                    <span class="badge bg-secondary">Staff</span>
                                @endif
                            </td>
                            <td class="text-muted small">{{ $user->created_at->format('d M Y') }}</td>
                            <td>
                                <button class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editModal{{ $user->id }}">
                                    Edit
                                </button>
                                @if($user->id !== auth()->id())
                                <form method="POST" action="{{ route('staff.destroy', $user) }}" class="d-inline"
                                    onsubmit="return confirm('Delete {{ $user->name }}? This cannot be undone.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Edit modals --}}
@foreach($staff as $user)
<div class="modal fade" id="editModal{{ $user->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('staff.update', $user) }}">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit: {{ $user->name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input type="text" name="name" class="form-control" value="{{ $user->name }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control" value="{{ $user->email }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role</label>
                        <select name="role" class="form-select">
                            <option value="staff"  {{ $user->role === 'staff'  ? 'selected' : '' }}>Staff</option>
                            <option value="admin"  {{ $user->role === 'admin'  ? 'selected' : '' }}>Admin</option>
                        </select>
                    </div>
                    <hr>
                    <div class="text-muted small mb-3">Leave password blank to keep the current password.</div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password</label>
                        <input type="password" name="password" class="form-control" minlength="8" placeholder="Min. 8 characters">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Confirm New Password</label>
                        <input type="password" name="password_confirmation" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

@endsection
