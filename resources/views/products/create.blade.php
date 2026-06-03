@extends('layouts.app')
@section('title', 'Add Product')
@section('page-title', 'Add Product')

@push('styles')
<style>
.form-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.25rem;
}
.form-section-title {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .09em;
    color: #94a3b8;
    margin-bottom: 1.1rem;
    display: flex;
    align-items: center;
    gap: .4rem;
}
.promo-exclude-box {
    border: 2px solid #fca5a5;
    background: #fff5f5;
    border-radius: 10px;
    padding: 1rem 1.2rem;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    cursor: pointer;
    transition: border-color .15s, background .15s;
    user-select: none;
}
.promo-exclude-box.active { border-color: #ef4444; background: #fee2e2; }
.promo-exclude-box input[type=checkbox] {
    width: 1.2rem; height: 1.2rem; margin-top: .15rem;
    flex-shrink: 0; accent-color: #ef4444; cursor: pointer;
}
.variant-row {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: .65rem .9rem;
    margin-bottom: .5rem;
}
.img-preview-wrap { position: relative; display: inline-block; }
.img-preview-wrap img { border-radius: 8px; border: 1px solid #e2e8f0; object-fit: cover; }
#imgSizeWarning { display: none; }
</style>
@endpush

@section('content')
<div class="row justify-content-center">
<div class="col-xl-8 col-lg-10">
<form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" id="productForm">
@csrf

{{-- ── Basic Information ── --}}
<div class="form-section">
    <div class="form-section-title"><i class="bi bi-tag-fill"></i> Basic Information</div>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
            <input type="text" name="name"
                class="form-control @error('name') is-invalid @enderror"
                value="{{ old('name') }}" placeholder="e.g. Floral Midi Dress" required autofocus>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Product Code</label>
            <input type="text" name="product_code" id="productCodeInput"
                class="form-control font-monospace"
                value="{{ old('product_code') }}"
                placeholder="e.g. NA_01 or NZ_01"
                oninput="this.value=this.value.toUpperCase(); checkZCode(this.value)">
            <div class="form-text">Prefix ending in <strong>Z</strong> (NZ, MZ…) auto-excludes from promos.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Brand</label>
            <input type="text" name="brand" class="form-control"
                value="{{ old('brand') }}" placeholder="Brand name">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">SKU</label>
            <input type="text" name="sku" class="form-control"
                value="{{ old('sku') }}" placeholder="Internal SKU">
        </div>
    </div>
</div>

{{-- ── Trip, Price & Weight ── --}}
<div class="form-section">
    <div class="form-section-title"><i class="bi bi-airplane-fill"></i> Trip & Pricing</div>
    <div class="row g-3">
        <div class="col-md-5">
            <label class="form-label fw-semibold">Trip <span class="text-danger">*</span></label>
            <select name="trip_id" class="form-select @error('trip_id') is-invalid @enderror" required>
                <option value="">Select trip…</option>
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}"
                        {{ (old('trip_id', $selectedTrip?->id) == $trip->id) ? 'selected' : '' }}>
                        {{ $trip->name }}
                    </option>
                @endforeach
            </select>
            @error('trip_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">
                Selling Price (Rp) <span class="text-danger">*</span>
            </label>
            <div class="input-group">
                <span class="input-group-text text-muted">Rp</span>
                <input type="number" name="price"
                    class="form-control @error('price') is-invalid @enderror"
                    value="{{ old('price', 0) }}" min="0" step="1000" required>
            </div>
            @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Weight per Item</label>
            <div class="input-group">
                <input type="number" name="weight_gram" class="form-control"
                    value="{{ old('weight_gram', 0) }}" min="0" step="1" placeholder="0">
                <span class="input-group-text text-muted">gram</span>
            </div>
            <div class="form-text">For shipping calculation</div>
        </div>
    </div>
</div>

{{-- ── Promo Settings ── --}}
<div class="form-section">
    <div class="form-section-title"><i class="bi bi-percent"></i> Promo Settings</div>
    <label class="promo-exclude-box w-100" id="promoExcludeBox" for="excludedFromPromo">
        <input type="checkbox" name="excluded_from_promo" value="1" id="excludedFromPromo"
            {{ old('excluded_from_promo') ? 'checked' : '' }}
            onchange="this.closest('.promo-exclude-box').classList.toggle('active', this.checked)">
        <div>
            <div class="fw-semibold" style="color:#dc2626;">
                <i class="bi bi-slash-circle me-1"></i>Exclude from all promo discounts
            </div>
            <div class="text-muted small mt-1">
                This product will <strong>not count</strong> toward any promo threshold
                and will not receive any discount — regardless of promo rules.
                Auto-enabled for product codes ending in Z (NZ, MZ, AZ, PZ…).
            </div>
        </div>
    </label>
</div>

{{-- ── Image & Note ── --}}
<div class="form-section">
    <div class="form-section-title"><i class="bi bi-card-image"></i> Image & Notes</div>
    <div class="row g-3">
        <div class="col-md-5">
            <label class="form-label fw-semibold">Product Image</label>
            <input type="file" name="image" id="imageInput" class="form-control" accept="image/*"
                onchange="previewImage(this)">
            <div id="imgSizeWarning" class="alert alert-warning py-2 px-3 mt-2 small">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>File too large.</strong> Please use an image under <strong>500 KB</strong>
                (recommended 100–300 KB) for best performance.
            </div>
            <div class="form-text">Max 500 KB · JPG or PNG recommended</div>
            <div class="mt-2 img-preview-wrap" id="imgPreviewWrap" style="display:none;">
                <img id="imgPreview" src="" height="90" alt="Preview">
                <div class="text-muted small mt-1" id="imgSizeInfo"></div>
            </div>
        </div>
        <div class="col-md-7">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="4"
                placeholder="Staff notes, material, care instructions…">{{ old('notes') }}</textarea>
        </div>
    </div>
</div>

{{-- ── Variants ── --}}
<div class="form-section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-section-title mb-0"><i class="bi bi-collection-fill"></i> Variants (Color / Size)</div>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addVariant">
            <i class="bi bi-plus-lg me-1"></i>Add Variant
        </button>
    </div>
    <div id="variantsContainer">
        <div class="variant-row row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="variants[0][color]" class="form-control form-control-sm"
                    placeholder="Color (e.g. Black)">
            </div>
            <div class="col-md-3">
                <input type="text" name="variants[0][size]" class="form-control form-control-sm"
                    placeholder="Size (e.g. M, XL)">
            </div>
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text text-muted">±Rp</span>
                    <input type="number" name="variants[0][price_adjustment]"
                        class="form-control" placeholder="Price adjustment" value="0" step="1000">
                </div>
            </div>
            <div class="col-md-1 text-center">
                <button type="button" class="btn btn-sm btn-outline-danger remove-variant">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2 pb-4">
    <button type="submit" class="btn btn-primary px-4" id="submitBtn">
        <i class="bi bi-check-lg me-1"></i>Create Product
    </button>
    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Cancel</a>
</div>

</form>
</div>
</div>
@endsection

@push('scripts')
<script>
let variantIndex = 1;

/* ── Z-code auto-exclude ── */
function checkZCode(val) {
    const prefix = (val.split('_')[0] || '').toUpperCase();
    if (prefix.length >= 2 && prefix.endsWith('Z')) {
        document.getElementById('excludedFromPromo').checked = true;
        document.getElementById('promoExcludeBox').classList.add('active');
    }
}

/* ── Image preview + size check ── */
function previewImage(input) {
    const warning  = document.getElementById('imgSizeWarning');
    const preview  = document.getElementById('imgPreview');
    const wrap     = document.getElementById('imgPreviewWrap');
    const sizeInfo = document.getElementById('imgSizeInfo');
    const submitBtn = document.getElementById('submitBtn');

    if (!input.files || !input.files[0]) {
        wrap.style.display = 'none';
        warning.style.display = 'none';
        return;
    }

    const file    = input.files[0];
    const sizeKB  = Math.round(file.size / 1024);
    const tooLarge = file.size > 500 * 1024; // 500 KB

    // Show warning, block submit if too large
    warning.style.display  = tooLarge ? 'block' : 'none';
    submitBtn.disabled      = tooLarge;

    // Show preview regardless
    const reader = new FileReader();
    reader.onload = e => {
        preview.src       = e.target.result;
        wrap.style.display = 'block';
        sizeInfo.textContent = `${sizeKB} KB${tooLarge ? ' — too large!' : ' ✓'}`;
        sizeInfo.className   = tooLarge ? 'text-danger small mt-1' : 'text-success small mt-1';
    };
    reader.readAsDataURL(file);
}

/* ── Init promo box state ── */
document.getElementById('promoExcludeBox')
    .classList.toggle('active', document.getElementById('excludedFromPromo').checked);

/* ── Add variant ── */
document.getElementById('addVariant').addEventListener('click', function () {
    const container = document.getElementById('variantsContainer');
    const row = document.createElement('div');
    row.className = 'variant-row row g-2 align-items-center';
    row.innerHTML = `
        <div class="col-md-4">
            <input type="text" name="variants[${variantIndex}][color]"
                class="form-control form-control-sm" placeholder="Color">
        </div>
        <div class="col-md-3">
            <input type="text" name="variants[${variantIndex}][size]"
                class="form-control form-control-sm" placeholder="Size">
        </div>
        <div class="col-md-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text text-muted">±Rp</span>
                <input type="number" name="variants[${variantIndex}][price_adjustment]"
                    class="form-control" placeholder="Price adj." value="0" step="1000">
            </div>
        </div>
        <div class="col-md-1 text-center">
            <button type="button" class="btn btn-sm btn-outline-danger remove-variant">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>`;
    container.appendChild(row);
    variantIndex++;
});

document.addEventListener('click', function (e) {
    if (e.target.closest('.remove-variant')) {
        e.target.closest('.variant-row').remove();
    }
});
</script>
@endpush
