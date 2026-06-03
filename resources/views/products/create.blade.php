@extends('layouts.app')
@section('title', 'Add Product')
@section('page-title', 'Add Product')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card">
<div class="card-body p-4">
<form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data">
    @csrf

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                value="{{ old('name') }}" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Product Code</label>
            <input type="text" name="product_code" class="form-control text-uppercase"
                value="{{ old('product_code') }}" placeholder="e.g. NA_01, NZ_01">
            <div class="form-text">Prefix before _ is used for promo exclusion (NZ, MZ, PZ…)</div>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Brand</label>
            <input type="text" name="brand" class="form-control" value="{{ old('brand') }}">
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Trip <span class="text-danger">*</span></label>
            <select name="trip_id" class="form-select @error('trip_id') is-invalid @enderror" required>
                <option value="">Select trip…</option>
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}" {{ (old('trip_id', $selectedTrip?->id) == $trip->id) ? 'selected' : '' }}>
                        {{ $trip->name }}
                    </option>
                @endforeach
            </select>
            @error('trip_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Base Price (Rp) <span class="text-danger">*</span></label>
            <input type="number" name="price" class="form-control @error('price') is-invalid @enderror"
                value="{{ old('price', 0) }}" min="0" step="1000" required>
            @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Weight (gram)</label>
            <div class="input-group">
                <input type="number" name="weight_gram" class="form-control" value="{{ old('weight_gram', 0) }}" min="0" step="1">
                <span class="input-group-text">g</span>
            </div>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Description</label>
        <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
    </div>

    <div class="mb-3">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="excluded_from_promo" value="1" id="excludedFromPromo"
                {{ old('excluded_from_promo') ? 'checked' : '' }}>
            <label class="form-check-label fw-semibold" for="excludedFromPromo">
                Exclude from all promos
            </label>
        </div>
        <div class="form-text">When ticked, this product will <strong>never count</strong> toward any promo discount threshold, regardless of promo rules.</div>
    </div>
        <input type="file" name="image" class="form-control" accept="image/*">
    </div>

    {{-- Variants --}}
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label fw-semibold mb-0">Variants (Color / Size)</label>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="addVariant">
                <i class="bi bi-plus-lg me-1"></i>Add Variant
            </button>
        </div>
        <div id="variantsContainer">
            <div class="row g-2 mb-2 variant-row align-items-center">
                <div class="col-md-4"><input type="text" name="variants[0][color]" class="form-control form-control-sm" placeholder="Color (e.g. Black)"></div>
                <div class="col-md-3"><input type="text" name="variants[0][size]" class="form-control form-control-sm" placeholder="Size (e.g. M)"></div>
                <div class="col-md-3"><input type="number" name="variants[0][price_adjustment]" class="form-control form-control-sm" placeholder="Price adj. (Rp)" value="0" step="1000"></div>
                <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-variant">×</button></div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Create Product</button>
        <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
</div>
</div>
</div>
</div>
@endsection

@push('scripts')
<script>
let variantIndex = 1;
document.getElementById('addVariant').addEventListener('click', function() {
    const container = document.getElementById('variantsContainer');
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 variant-row align-items-center';
    row.innerHTML = `
        <div class="col-md-4"><input type="text" name="variants[${variantIndex}][color]" class="form-control form-control-sm" placeholder="Color"></div>
        <div class="col-md-3"><input type="text" name="variants[${variantIndex}][size]" class="form-control form-control-sm" placeholder="Size"></div>
        <div class="col-md-3"><input type="number" name="variants[${variantIndex}][price_adjustment]" class="form-control form-control-sm" placeholder="Price adj." value="0" step="1000"></div>
        <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-variant">×</button></div>
    `;
    container.appendChild(row);
    variantIndex++;
});
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-variant')) {
        e.target.closest('.variant-row').remove();
    }
});
</script>
@endpush
