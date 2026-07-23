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

    {{-- Pricing mode toggle --}}
    <div class="mb-3">
        <label class="form-label fw-semibold">Pricing Mode</label>
        <div class="d-flex gap-3">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="pricing_mode" id="modePerKg" value="per_kg"
                    {{ (old('pricing_mode', isset($shipping) && $shipping->isFlatFee() ? 'flat' : 'per_kg')) === 'per_kg' ? 'checked' : '' }}>
                <label class="form-check-label" for="modePerKg">Per kg</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="pricing_mode" id="modeFlat" value="flat"
                    {{ (old('pricing_mode', isset($shipping) && $shipping->isFlatFee() ? 'flat' : 'per_kg')) === 'flat' ? 'checked' : '' }}>
                <label class="form-check-label" for="modeFlat">Flat fee (fixed regardless of weight)</label>
            </div>
        </div>
    </div>

    {{-- Per kg fields --}}
    <div id="perKgFields" class="mb-3">
        <label class="form-label fw-semibold">Price per kg (Rp) <span class="text-danger">*</span></label>
        <div class="input-group">
            <span class="input-group-text">Rp</span>
            <input type="number" name="price_per_kg" id="pricePerKg"
                class="form-control @error('price_per_kg') is-invalid @enderror"
                value="{{ old('price_per_kg', $shipping->price_per_kg ?? '') }}"
                min="0" step="500">
        </div>
        @error('price_per_kg')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    </div>

    {{-- Flat fee fields --}}
    <div id="flatFeeFields" class="mb-3">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Flat Fee (Rp)</label>
                <div class="input-group">
                    <span class="input-group-text">Rp</span>
                    <input type="number" name="flat_fee" id="flatFee"
                        class="form-control @error('flat_fee') is-invalid @enderror"
                        value="{{ old('flat_fee', $shipping->flat_fee ?? '') }}"
                        min="0" step="500" placeholder="e.g. 10000">
                </div>
                <div class="form-text">Fixed shipping fee regardless of weight.</div>
                @error('flat_fee')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Subsidy Cap (Rp)</label>
                <div class="input-group">
                    <span class="input-group-text">Rp</span>
                    <input type="number" name="flat_fee_subsidy_cap" id="flatFeeSubsidyCap"
                        class="form-control @error('flat_fee_subsidy_cap') is-invalid @enderror"
                        value="{{ old('flat_fee_subsidy_cap', $shipping->flat_fee_subsidy_cap ?? '') }}"
                        min="0" step="500" placeholder="Leave blank = same as flat fee">
                </div>
                <div class="form-text">Max promo can subsidise. Leave blank to cap at the flat fee amount.</div>
                @error('flat_fee_subsidy_cap')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>

    {{-- Live preview --}}
    <div class="card bg-light p-3 mb-3">
        <div class="small fw-semibold mb-2">Shipping Fee Preview</div>
        <table class="table table-sm mb-0 small">
            <thead><tr><th>Weight</th><th>Charged as</th><th>Fee</th></tr></thead>
            <tbody id="previewTable">
                <tr><td colspan="3" class="text-muted">Enter price above…</td></tr>
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
    if (grams <= 1320) return 1;
    return Math.ceil((grams - 320) / 1000);
}
function fmt(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }

const samples = [
    {label: '300g',   grams: 300},
    {label: '500g',   grams: 500},
    {label: '1 kg',   grams: 1000},
    {label: '1.32kg', grams: 1320},
    {label: '1.5kg',  grams: 1500},
    {label: '2kg',    grams: 2000},
    {label: '2.32kg', grams: 2320},
    {label: '3kg',    grams: 3000},
    {label: '5kg',    grams: 5000},
];

function isFlat() {
    return document.getElementById('modeFlat').checked;
}

function updateVisibility() {
    document.getElementById('perKgFields').style.display = isFlat() ? 'none' : 'block';
    document.getElementById('flatFeeFields').style.display = isFlat() ? 'block' : 'none';
    updatePreview();
}

function updatePreview() {
    const tbody = document.getElementById('previewTable');
    if (isFlat()) {
        const fee = parseFloat(document.getElementById('flatFee').value) || 0;
        if (!fee) { tbody.innerHTML = '<tr><td colspan="3" class="text-muted">Enter flat fee above…</td></tr>'; return; }
        tbody.innerHTML = samples.map(s =>
            `<tr><td>${s.label}</td><td>flat</td><td class="fw-semibold text-success">${fmt(fee)} <span class="text-muted">(same for all weights)</span></td></tr>`
        ).join('');
    } else {
        const price = parseFloat(document.getElementById('pricePerKg').value) || 0;
        if (!price) { tbody.innerHTML = '<tr><td colspan="3" class="text-muted">Enter price per kg above…</td></tr>'; return; }
        tbody.innerHTML = samples.map(s => {
            const kg  = calcKg(s.grams);
            const fee = kg * price;
            return `<tr><td>${s.label}</td><td>${kg} kg</td><td class="fw-semibold">${fmt(fee)}</td></tr>`;
        }).join('');
    }
}

document.getElementById('modePerKg').addEventListener('change', updateVisibility);
document.getElementById('modeFlat').addEventListener('change', updateVisibility);
document.getElementById('pricePerKg').addEventListener('input', updatePreview);
document.getElementById('flatFee').addEventListener('input', updatePreview);

updateVisibility();
</script>
@endpush