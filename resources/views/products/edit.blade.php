@extends('layouts.app')
@section('title', 'Edit Product')
@section('page-title', 'Edit Product')

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
#imgSizeWarning { display: none; }
</style>
@endpush

@section('content')
<div class="row justify-content-center">
<div class="col-xl-8 col-lg-10">
<form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data">
@csrf @method('PUT')

{{-- ── Basic Information ── --}}
<div class="form-section">
    <div class="form-section-title"><i class="bi bi-tag-fill"></i> Basic Information</div>
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control"
                value="{{ old('name', $product->name) }}" required>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Product Code</label>
            <input type="text" name="product_code" id="productCodeInput"
                class="form-control font-monospace"
                value="{{ old('product_code', $product->product_code) }}"
                oninput="this.value=this.value.toUpperCase(); checkZCode(this.value)">
            <div class="form-text">Prefix ending in Z → auto-excludes from promos</div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Brand</label>
            <input type="text" name="brand" class="form-control"
                value="{{ old('brand', $product->brand) }}">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">SKU</label>
            <input type="text" name="sku" class="form-control"
                value="{{ old('sku', $product->sku) }}">
        </div>
    </div>
</div>

{{-- ── Trip, Price & Weight ── --}}
<div class="form-section">
    <div class="form-section-title"><i class="bi bi-airplane-fill"></i> Trip & Pricing</div>
    <div class="row g-3">
        <div class="col-md-5">
            <label class="form-label fw-semibold">Trip <span class="text-danger">*</span></label>
            <select name="trip_id" class="form-select" required>
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}"
                        {{ old('trip_id', $product->trip_id) == $trip->id ? 'selected' : '' }}>
                        {{ $trip->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Selling Price (Rp) <span class="text-danger">*</span></label>
            <div class="input-group">
                <span class="input-group-text text-muted">Rp</span>
                <input type="number" name="price" class="form-control"
                    value="{{ old('price', $product->price) }}" min="0" step="1000" required>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Weight per Item</label>
            <div class="input-group">
                <input type="number" name="weight_gram" class="form-control"
                    value="{{ old('weight_gram', $product->weight_gram) }}" min="0" step="1">
                <span class="input-group-text text-muted">gram</span>
            </div>
        </div>
        <div class="col-md-4 offset-md-8">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" class="form-select">
                @foreach(['active' => 'Active', 'closed' => 'Closed', 'arrived' => 'Arrived'] as $val => $lbl)
                    <option value="{{ $val }}"
                        {{ old('status', $product->status) == $val ? 'selected' : '' }}>
                        {{ $lbl }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
</div>

{{-- ── Promo Settings ── --}}
<div class="form-section">
    <div class="form-section-title"><i class="bi bi-percent"></i> Promo Settings</div>
    <label class="promo-exclude-box w-100 {{ old('excluded_from_promo', $product->excluded_from_promo) ? 'active' : '' }}"
        id="promoExcludeBox" for="excludedFromPromo">
        <input type="checkbox" name="excluded_from_promo" value="1" id="excludedFromPromo"
            {{ old('excluded_from_promo', $product->excluded_from_promo) ? 'checked' : '' }}
            onchange="this.closest('.promo-exclude-box').classList.toggle('active', this.checked)">
        <div>
            <div class="fw-semibold" style="color:#dc2626;">
                <i class="bi bi-slash-circle me-1"></i>Exclude from all promo discounts
            </div>
            <div class="text-muted small mt-1">
                This product will not count toward any promo threshold and will not receive any discount.
                Auto-enabled for product codes ending in Z (NZ, MZ, AZ, PZ…).
            </div>
        </div>
    </label>
</div>

{{-- ── Image & Notes ── --}}
<div class="form-section">
    <div class="form-section-title"><i class="bi bi-card-image"></i> Image & Notes</div>
    <div class="row g-3">
        <div class="col-md-5">
            <label class="form-label fw-semibold">Product Image</label>
            @if($product->image)
                <div class="mb-2">
                    <img src="{{ asset('storage/'.$product->image) }}" height="80"
                        class="rounded border" alt="Current image">
                    <div class="text-muted small mt-1">Current image</div>
                </div>
            @endif
            <input type="file" name="image" id="imageInput" class="form-control" accept="image/*"
                onchange="previewImage(this)">
            <div id="imgSizeWarning" class="alert alert-warning py-2 px-3 mt-2 small">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>File too large.</strong> Please use an image under <strong>500 KB</strong>
                (recommended 100–300 KB).
            </div>
            <div class="form-text">Max 500 KB · leave blank to keep current</div>
            <div class="mt-2" id="imgPreviewWrap" style="display:none;">
                <img id="imgPreview" src="" height="80" class="rounded border" alt="New preview">
                <div id="imgSizeInfo" class="small mt-1"></div>
            </div>
        </div>
        <div class="col-md-7">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="4"
                placeholder="Staff notes, material, care instructions…">{{ old('notes', $product->notes ?? $product->description) }}</textarea>
        </div>
    </div>
</div>

<div class="d-flex gap-2 pb-4">
    <button type="submit" class="btn btn-primary px-4" id="submitBtn">
        <i class="bi bi-check-lg me-1"></i>Update Product
    </button>
    <a href="{{ route('products.show', $product) }}" class="btn btn-outline-secondary">Cancel</a>
    <form method="POST" action="{{ route('products.destroy', $product) }}" class="ms-auto"
        onsubmit="return confirm('Delete this product?')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-outline-danger">
            <i class="bi bi-trash3 me-1"></i>Delete
        </button>
    </form>
</div>

</form>
</div>
</div>
@endsection

@push('scripts')
<script>
function checkZCode(val) {
    const prefix = (val.split('_')[0] || '').toUpperCase();
    if (prefix.length >= 2 && prefix.endsWith('Z')) {
        document.getElementById('excludedFromPromo').checked = true;
        document.getElementById('promoExcludeBox').classList.add('active');
    }
}

function previewImage(input) {
    const warning   = document.getElementById('imgSizeWarning');
    const preview   = document.getElementById('imgPreview');
    const wrap      = document.getElementById('imgPreviewWrap');
    const sizeInfo  = document.getElementById('imgSizeInfo');
    const submitBtn = document.getElementById('submitBtn');

    if (!input.files || !input.files[0]) {
        wrap.style.display = 'none';
        warning.style.display = 'none';
        return;
    }

    const file     = input.files[0];
    const sizeKB   = Math.round(file.size / 1024);
    const tooLarge = file.size > 500 * 1024;

    warning.style.display = tooLarge ? 'block' : 'none';
    submitBtn.disabled     = tooLarge;

    const reader = new FileReader();
    reader.onload = e => {
        preview.src          = e.target.result;
        wrap.style.display   = 'block';
        sizeInfo.textContent = `${sizeKB} KB${tooLarge ? ' — too large!' : ' ✓'}`;
        sizeInfo.className   = tooLarge ? 'text-danger small mt-1' : 'text-success small mt-1';
    };
    reader.readAsDataURL(file);
}
</script>
@endpush
