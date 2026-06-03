@extends('layouts.app')
@section('title', isset($shipping) ? 'Edit Shipping Area' : 'Add Shipping Area')
@section('page-title', isset($shipping) ? 'Edit Shipping Area' : 'Add Shipping Area')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-6">
<div class="card">
<div class="card-body p-4">
<form method="POST" action="{{ isset($shipping) ? route('shipping.update', $shipping) : route('shipping.store') }}">
    @csrf
    @if(isset($shipping)) @method('PUT') @endif

    <div class="row g-3 mb-3">
        <div class="col-md-7">
            <label class="form-label fw-semibold">Area / City Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                value="{{ old('name', $shipping->name ?? '') }}" placeholder="e.g. Batam, Jakarta Pusat" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-5">
            <label class="form-label fw-semibold">Province</label>
            <input type="text" name="province" class="form-control"
                value="{{ old('province', $shipping->province ?? '') }}" placeholder="e.g. Kepulauan Riau">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Price per kg (Rp) <span class="text-danger">*</span></label>
        <div class="input-group">
            <span class="input-group-text">Rp</span>
            <input type="number" name="price_per_kg" id="pricePerKg"
                class="form-control @error('price_per_kg') is-invalid @enderror"
                value="{{ old('price_per_kg', $shipping->price_per_kg ?? '') }}"
                min="0" step="500" required>
        </div>
        @error('price_per_kg')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    </div>

    {{-- Live preview --}}
    <div class="card bg-light p-3 mb-3">
        <div class="small fw-semibold mb-2">Shipping Fee Preview</div>
        <table class="table table-sm mb-0 small">
            <thead><tr><th>Weight</th><th>Charged as</th><th>Fee</th></tr></thead>
            <tbody id="previewTable">
                <tr><td colspan="3" class="text-muted">Enter price per kg above…</td></tr>
            </tbody>
        </table>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Notes</label>
        <textarea name="notes" class="form-control" rows="2">{{ old('notes', $shipping->notes ?? '') }}</textarea>
    </div>

    <div class="mb-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                {{ old('is_active', $shipping->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label fw-semibold" for="isActive">Active</label>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">{{ isset($shipping) ? 'Update' : 'Save' }}</button>
        <a href="{{ route('shipping.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
</div>
</div>
</div>
</div>
@endsection

@push('scripts')
<script>
function calcKg(grams) {
    if (grams <= 0) return 0;
    if (grams <= 1350) return 1;
    return Math.ceil((grams - 350) / 1000);
}
function fmt(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }

function updatePreview() {
    const price = parseFloat(document.getElementById('pricePerKg').value) || 0;
    const samples = [
        {label: '300g',   grams: 300},
        {label: '500g',   grams: 500},
        {label: '1 kg',   grams: 1000},
        {label: '1.35kg', grams: 1350},
        {label: '1.5kg',  grams: 1500},
        {label: '2kg',    grams: 2000},
        {label: '2.35kg', grams: 2350},
        {label: '3kg',    grams: 3000},
        {label: '5kg',    grams: 5000},
    ];
    const tbody = document.getElementById('previewTable');
    if (!price) { tbody.innerHTML = '<tr><td colspan="3" class="text-muted">Enter price per kg above…</td></tr>'; return; }
    tbody.innerHTML = samples.map(s => {
        const kg  = calcKg(s.grams);
        const fee = kg * price;
        return `<tr><td>${s.label}</td><td>${kg} kg</td><td class="fw-semibold">${fmt(fee)}</td></tr>`;
    }).join('');
}

document.getElementById('pricePerKg').addEventListener('input', updatePreview);
updatePreview();
</script>
@endpush
