@extends('layouts.app')
@section('title', 'Staff Accounts')
@section('page-title', 'Staff Accounts')

@push('styles')
<style>
.role-badge { font-size:.72rem; padding:2px 8px; border-radius:10px; font-weight:600; }
.role-admin      { background:#fef3c7;color:#92400e; }
.role-finance    { background:#dbeafe;color:#1e40af; }
.role-purchasing { background:#f3e8ff;color:#6b21a8; }
.role-staff      { background:#dcfce7;color:#166534; }
.role-viewer     { background:#f1f5f9;color:#475569; }
.perm-group { margin-bottom:.75rem; }
.perm-group-title { font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem; }
.perm-row { display:flex;align-items:center;gap:.5rem;font-size:.82rem;padding:2px 0; }
.perm-row .form-check-input { margin:0; }
.perm-override { color:#6366f1;font-size:.68rem;margin-left:.25rem; }
/* Safety net: keep modals fully hidden until Bootstrap opens them.
   Prevents both create + edit forms rendering inline if JS is slow/fails. */
.modal.fade:not(.show) { display: none !important; }
</style>
@endpush

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="text-muted small">{{ $staff->count() }} account(s)</div>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-person-plus me-1"></i>Add Account
    </button>
</div>

{{-- Staff list --}}
<div class="row g-3">
    @foreach($staff as $user)
    @php
        $defaults = \App\Models\User::roleDefaults($user->role);
        $customs  = $user->permissions ?? [];
        // Only count entries that genuinely differ from role defaults
        $trueOverrides = array_filter($customs, fn($val, $perm) =>
            (bool)($defaults[$perm] ?? false) !== (bool)$val,
            ARRAY_FILTER_USE_BOTH
        );
        $hasOverrides = !empty($trueOverrides);
    @endphp
    <div class="col-12">
        <div class="card {{ !$user->is_active ? 'opacity-50' : '' }}">
            <div class="card-body py-3">
                <div class="row align-items-center g-2">
                    {{-- Avatar + name --}}
                    <div class="col-md-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                style="width:38px;height:38px;font-size:.9rem;font-weight:700;flex-shrink:0;">
                                {{ strtoupper(substr($user->name,0,1)) }}
                            </div>
                            <div>
                                <div class="fw-semibold">{{ $user->name }}
                                    @if($user->id === auth()->id())
                                        <span class="badge bg-secondary ms-1" style="font-size:.65rem;">You</span>
                                    @endif
                                    @if(!$user->is_active)
                                        <span class="badge bg-danger ms-1" style="font-size:.65rem;">Inactive</span>
                                    @endif
                                </div>
                                <div class="text-muted small">{{ $user->email }}</div>
                                @if($user->phone)
                                    <div class="text-muted" style="font-size:.75rem;">{{ $user->phone }}</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Role --}}
                    <div class="col-md-2">
                        <span class="role-badge role-{{ $user->role }}">{{ ucfirst($user->role) }}</span>
                        @if($hasOverrides)
                            <div style="font-size:.68rem;color:#6366f1;margin-top:2px;">
                                <i class="bi bi-sliders me-1"></i>{{ count($trueOverrides) }} custom override(s)
                            </div>
                        @endif
                    </div>

                    {{-- Permission summary --}}
                    <div class="col-md-5">
                        @php
                            $groups = [
                                'Orders'     => ['orders.view','orders.create','orders.edit','orders.delete','orders.import','orders.export'],
                                'Customers'  => ['customers.view','customers.create','customers.edit','customers.delete','customers.import','customers.export'],
                                'Products'   => ['products.view','products.create','products.edit','products.delete','products.import','products.export'],
                                'Purchasing' => ['purchasing.view','purchasing.edit'],
                                'Payments'   => ['payments.view','payments.record','payments.void'],
                                'Promos'     => ['promos.view','promos.create','promos.edit','promos.delete'],
                                'Other'      => ['invoices.view','trips.view','trips.edit','reports.view','settings.view','settings.edit'],
                            ];
                        @endphp
                        <div class="d-flex flex-wrap gap-1">
                            @foreach($groups as $groupName => $perms)
                            @php
                                $allowed = collect($perms)->filter(fn($p) => $user->hasPermission($p))->count();
                                $total   = count($perms);
                            @endphp
                            <span class="badge {{ $allowed === $total ? 'bg-success' : ($allowed === 0 ? 'bg-light text-muted border' : 'bg-warning text-dark') }}"
                                style="font-size:.68rem;" title="{{ $groupName }}: {{ $allowed }}/{{ $total }} permissions">
                                {{ $groupName }} {{ $allowed }}/{{ $total }}
                            </span>
                            @endforeach
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="col-md-2 text-end">
                        <button class="btn btn-sm btn-outline-secondary"
                            onclick="openEditModal({{ $user->id }})">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                        @if($user->id !== auth()->id())
                        <form method="POST" action="{{ route('staff.destroy', $user) }}"
                            class="d-inline" onsubmit="return confirm('Delete {{ $user->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- Create Modal --}}
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ route('staff.store') }}">
                @csrf
                <div class="modal-body">
                    @include('staff._form', ['user' => null, 'roles' => $roles, 'allPermissions' => $allPermissions])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Modals (one per user) --}}
@foreach($staff as $user)
<div class="modal fade" id="editModal-{{ $user->id }}" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit — {{ $user->name }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ route('staff.update', $user) }}">
                @csrf @method('PUT')
                <div class="modal-body">
                    @include('staff._form', ['user' => $user, 'roles' => $roles, 'allPermissions' => $allPermissions])
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

@push('scripts')
<script>
function openEditModal(userId) {
    new bootstrap.Modal(document.getElementById('editModal-' + userId)).show();
}

// When role changes in create/edit form, refresh permission checkboxes to defaults
function onRoleChange(selectEl, prefix) {
    const role    = selectEl.value;
    const defaults = {!! json_encode(
        collect(['admin','finance','staff'])
            ->mapWithKeys(fn($r) => [$r => \App\Models\User::roleDefaults($r)])
    ) !!};

    if (!defaults[role]) return;
    Object.entries(defaults[role]).forEach(([perm, allowed]) => {
        const cb = document.querySelector(`input[name="${prefix}[${perm}]"]`);
        if (cb) cb.checked = allowed;
    });
}
</script>
@endpush