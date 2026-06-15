@php
    $isEdit   = $user !== null;
    $prefix   = 'permissions';
    $defaults = $isEdit ? \App\Models\User::roleDefaults($user->role) : \App\Models\User::roleDefaults('staff');
    $customs  = $isEdit ? ($user->permissions ?? []) : [];
    $groups   = [
        'Orders'     => [
            'orders.view'    => 'View orders',
            'orders.create'  => 'Create orders',
            'orders.edit'    => 'Edit orders',
            'orders.delete'  => 'Delete orders',
            'orders.import'  => 'Import orders',
            'orders.export'  => 'Export orders',
        ],
        'Customers'  => [
            'customers.view'    => 'View customers',
            'customers.create'  => 'Add customers',
            'customers.edit'    => 'Edit customers',
            'customers.delete'  => 'Delete customers',
            'customers.import'  => 'Import customers',
            'customers.export'  => 'Export customers',
        ],
        'Products'   => [
            'products.view'   => 'View products',
            'products.create' => 'Add products',
            'products.edit'   => 'Edit products',
            'products.delete' => 'Delete products',
            'products.import' => 'Import products',
            'products.export' => 'Export products',
        ],
        'Shipping Areas' => [
            'shipping.view'   => 'View shipping areas',
            'shipping.create' => 'Add shipping areas',
            'shipping.edit'   => 'Edit shipping areas',
            'shipping.delete' => 'Delete shipping areas',
            'shipping.import' => 'Import shipping areas',
        ],
        'Purchasing' => [
            'purchasing.view' => 'View purchasing',
            'purchasing.edit' => 'Manage POs',
        ],
        'Suppliers' => [
            'suppliers.view'   => 'View suppliers',
            'suppliers.create' => 'Add suppliers',
            'suppliers.edit'   => 'Edit suppliers',
            'suppliers.delete' => 'Delete suppliers',
        ],
        'Payments'   => [
            'payments.view'   => 'View payments',
            'payments.record' => 'Record payments',
            'payments.void'   => 'Void payments',
            'payments.verify' => 'Verify / dispute payments',
            'payments.export' => 'Export payments',
        ],
        'Promo Rules' => [
            'promos.view'   => 'View promo rules',
            'promos.create' => 'Add promo rules',
            'promos.edit'   => 'Edit promo rules',
            'promos.delete' => 'Delete promo rules',
        ],
        'Data Scope' => [
            'own_data' => 'Own data only (staff sees only their own orders & payments — customers are always shared)',
        ],
        'Reports & Settings' => [
            'invoices.view'  => 'View invoices',
            'trips.view'     => 'View trips',
            'trips.edit'     => 'Manage trips',
            'reports.view'   => 'View reports',
            'settings.view'  => 'View settings',
            'settings.edit'  => 'Edit settings',
        ],
    ];
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control" required
            value="{{ $isEdit ? $user->name : old('name') }}" placeholder="e.g. Siti Rahma">
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
        <input type="email" name="email" class="form-control" required
            value="{{ $isEdit ? $user->email : old('email') }}" placeholder="staff@example.com">
    </div>
    <div class="col-md-4">
        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
        <select name="role" class="form-select" required
            onchange="onRoleChange(this, '{{ $prefix }}')">
            @foreach($roles as $r)
            <option value="{{ $r }}" {{ $isEdit && $user->role === $r ? 'selected' : (!$isEdit && $r==='staff' ? 'selected' : '') }}>
                {{ ucfirst($r) }}
            </option>
            @endforeach
        </select>
        <div class="form-text text-muted" style="font-size:.72rem;">
            Role sets default permissions below.
        </div>
    </div>
    <div class="col-md-4">
        <label class="form-label fw-semibold">Phone</label>
        <input type="text" name="phone" class="form-control"
            value="{{ $isEdit ? $user->phone : '' }}" placeholder="08xxx">
    </div>
    @if($isEdit)
    <div class="col-md-4 d-flex align-items-end pb-1">
        <div class="form-check form-switch ms-1">
            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                id="active_{{ $user->id }}" {{ $user->is_active ? 'checked' : '' }}>
            <label class="form-check-label" for="active_{{ $user->id }}">Active account</label>
        </div>
    </div>
    @endif
    <div class="col-md-6">
        <label class="form-label fw-semibold">Password {{ $isEdit ? '(leave blank to keep)' : '' }} <span class="{{ $isEdit ? '' : 'text-danger' }}">*</span></label>
        <input type="password" name="password" class="form-control" {{ $isEdit ? '' : 'required' }}
            placeholder="{{ $isEdit ? 'Leave blank to keep current' : 'Min 8 characters' }}">
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Confirm Password</label>
        <input type="password" name="password_confirmation" class="form-control">
    </div>
    <div class="col-12">
        <label class="form-label fw-semibold">Notes</label>
        <input type="text" name="notes" class="form-control"
            value="{{ $isEdit ? $user->notes : '' }}" placeholder="Optional — e.g. Handles Jakarta region">
    </div>
</div>

{{-- Permissions matrix --}}
<hr class="my-3">
<div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold small">Permissions</div>
    <div class="text-muted" style="font-size:.72rem;">
        <i class="bi bi-info-circle me-1"></i>Defaults set by role. Tick/untick to override.
    </div>
</div>

<div class="row g-3">
    @foreach($groups as $groupName => $perms)
    <div class="col-md-4">
        <div class="perm-group">
            <div class="perm-group-title">{{ $groupName }}</div>
            @foreach($perms as $perm => $label)
            @php
                $defaultVal = (bool)($defaults[$perm] ?? false);
                $customVal  = array_key_exists($perm, $customs) ? (bool)$customs[$perm] : $defaultVal;
                $isOverride = array_key_exists($perm, $customs) && (bool)$customs[$perm] !== $defaultVal;
            @endphp
            <div class="perm-row">
                <input class="form-check-input" type="checkbox"
                    name="{{ $prefix }}[{{ $perm }}]"
                    id="perm_{{ ($isEdit ? $user->id.'_' : '') }}{{ str_replace('.','_',$perm) }}"
                    {{ $customVal ? 'checked' : '' }}>
                <label class="form-check-label"
                    for="perm_{{ ($isEdit ? $user->id.'_' : '') }}{{ str_replace('.','_',$perm) }}">
                    {{ $label }}
                    @if($isOverride)
                        <span class="perm-override" title="Custom override">★</span>
                    @endif
                </label>
            </div>
            @endforeach
        </div>
    </div>
    @endforeach
</div>