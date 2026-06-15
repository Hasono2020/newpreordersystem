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
        <div class="col-md-4">
            <label class="form-label fw-semibold">Product Code <span class="text-danger">*</span></label>
            <input type="text" name="product_code" id="productCodeInput"
                class="form-control font-monospace"
                value="{{ old('product_code') }}"
                placeholder="e.g. NA_01 or NZ_01"
                required
                oninput="this.value=this.value.toUpperCase(); checkZCode(this.value); checkCodeUnique(this.value)">
            <div class="form-text">Prefix ending in <strong>Z</strong> (NZ, MZ…) auto-excludes from promos.</div>
            <div id="codeUniqueWarn" class="text-danger small mt-1" style="display:none;">
                <i class="bi bi-x-circle-fill me-1"></i><span id="codeUniqueMsg"></span>
            </div>
            <div id="codeUniqueOk" class="text-success small mt-1" style="display:none;">
                <i class="bi bi-check-circle-fill me-1"></i>Code is available
            </div>
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
        <div class="col-md-4">
            <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
            <input type="hidden" name="supplier_id" id="supplierId" required>
            <div class="position-relative">
                <input type="text" id="supplierSearch" class="form-control"
                    placeholder="Type to search supplier…" autocomplete="off">
                <div id="supplierDropdown" style="display:none;position:absolute;z-index:1050;width:100%;background:#fff;border:1px solid #dee2e6;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;">
                    <div id="supplierResults"></div>
                    <div id="supplierAddBtn" style="padding:.65rem 1rem;cursor:pointer;color:#2563eb;font-weight:600;font-size:.85rem;border-top:2px solid #e5e7eb;">
                        <i class="bi bi-plus-circle me-1"></i>Add new supplier…
                    </div>
                </div>
            </div>
            <div id="selectedSupplierCard" style="display:none;background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:.5rem .9rem;margin-top:.4rem;align-items:center;justify-content:space-between;">
                <div>
                    <span class="fw-semibold small" id="selectedSupplierName"></span>
                    <span class="text-muted small ms-2" id="selectedSupplierCountry"></span>
                </div>
                <button type="button" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2" id="clearSupplier">×</button>
            </div>
            <div id="supplierRequiredMsg" class="text-danger small mt-1" style="display:none;">
                <i class="bi bi-exclamation-circle-fill me-1"></i>Supplier is required before saving.
            </div>
            <div class="form-text">Type or pick existing. <a href="{{ route('suppliers.index') }}" target="_blank">Manage suppliers</a></div>
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
                Price (Rp) <span class="text-danger">*</span>
            </label>
            <div class="input-group">
                <span class="input-group-text text-muted">Rp</span>
                <input type="number" name="price"
                    class="form-control @error('price') is-invalid @enderror"
                    value="{{ old('price', 0) }}" min="0" step="1" required>
            </div>
            @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Weight per Item <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="number" name="weight_gram" id="weightInput" class="form-control"
                    value="{{ old('weight_gram') }}" min="1" step="1" placeholder="e.g. 350"
                    required
                    oninput="document.getElementById('weightWarnMsg').style.display = this.value < 1 ? 'block' : 'none'">
                <span class="input-group-text text-muted">gram</span>
            </div>
            <div class="form-text">For shipping calculation (required)</div>
            <div id="weightWarnMsg" class="text-warning small mt-1" style="display:none">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>Weight must be at least 1g.
            </div>
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
    <div class="alert alert-light border py-2 px-3 small mb-3">
        <i class="bi bi-info-circle me-1 text-primary"></i>
        <strong>Extra price</strong> = additional amount added on top of the base price above for this specific variant.
        Leave <strong>0</strong> if all variants share the same price.
        Example: base price Rp 110,000 + extra Rp 5,000 = customer pays <strong>Rp 115,000</strong> for this variant.
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
                    <input type="number" name="variants[0][price_adjustment]"
                        class="form-control" placeholder="Extra price for this variant (Rp), 0 = same price" value="0" step="1">
                    <span class="input-group-text text-muted">Rp extra</span>
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
<div class="modal fade" id="quickSupplierModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-building me-2"></i>Add New Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Supplier Name <span class="text-danger">*</span></label>
                    <input type="text" id="qsName" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Country</label>
                    <input type="text" id="qsCountry" class="form-control" placeholder="e.g. China, Korea" list="qsCountryList">
                    <datalist id="qsCountryList">
                        <option value="China"><option value="Korea"><option value="Japan"><option value="Thailand"><option value="Turkey">
                    </datalist>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Phone / WeChat</label>
                    <input type="text" id="qsPhone" class="form-control">
                </div>
                <div id="qsError" class="text-danger small" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveQuickSupplier">
                    <span id="qsSpinner" class="spinner-border spinner-border-sm me-1 d-none"></span>
                    Save Supplier
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let variantIndex = 1;

document.getElementById('productForm').addEventListener('submit', function(e) {
    const supplierId = document.getElementById('supplierId').value;
    const msg = document.getElementById('supplierRequiredMsg');
    if (!supplierId) {
        e.preventDefault();
        msg.style.display = 'block';
        document.getElementById('supplierId').closest('.col-md-4').scrollIntoView({behavior:'smooth'});
    }
});

/* ── Supplier search ── */
let supplierTimeout = null;
const supplierSearch   = document.getElementById('supplierSearch');
const supplierDropdown = document.getElementById('supplierDropdown');
const supplierResults  = document.getElementById('supplierResults');
const supplierCard     = document.getElementById('selectedSupplierCard');

// Preloaded suppliers — filter instantly in-browser, no API round-trips
const ALL_SUPPLIERS = @json($suppliers);
let supplierHighlight = -1;
let supplierFiltered  = [];

function renderSupplierList(list) {
    supplierResults.innerHTML = '';
    supplierFiltered = list;
    supplierHighlight = -1;
    if (!list.length) {
        supplierResults.innerHTML = '<div style="padding:.6rem 1rem;color:#94a3b8;font-size:.85rem;">No suppliers found</div>';
        return;
    }
    list.forEach((s, i) => {
        const d = document.createElement('div');
        d.dataset.idx = i;
        d.style.cssText = 'padding:.55rem 1rem;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:.875rem;';
        d.innerHTML = `<div style="font-weight:600;">${s.name}</div><div style="font-size:.75rem;color:#6b7280;">${s.country || ''}${s.phone ? ' · '+s.phone : ''}</div>`;
        d.addEventListener('mousedown', () => selectSupplier(s));
        d.addEventListener('mouseover', () => setHighlight(i));
        supplierResults.appendChild(d);
    });
}
function setHighlight(i) {
    supplierHighlight = i;
    [...supplierResults.children].forEach((el, idx) => { el.style.background = (idx === i) ? '#eaf1fb' : ''; });
}
function filterSuppliers(q) {
    q = (q || '').trim().toLowerCase();
    if (!q) return ALL_SUPPLIERS;
    return ALL_SUPPLIERS.filter(s =>
        s.name.toLowerCase().includes(q) ||
        (s.country || '').toLowerCase().includes(q) ||
        (s.phone || '').toLowerCase().includes(q));
}
supplierSearch.addEventListener('focus', function () {
    renderSupplierList(filterSuppliers(this.value));
    supplierDropdown.style.display = 'block';
});
supplierSearch.addEventListener('input', function () {
    renderSupplierList(filterSuppliers(this.value));
    supplierDropdown.style.display = 'block';
});
supplierSearch.addEventListener('keydown', function (e) {
    if (supplierDropdown.style.display === 'none') return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setHighlight(Math.min(supplierHighlight + 1, supplierFiltered.length - 1)); scrollHi(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setHighlight(Math.max(supplierHighlight - 1, 0)); scrollHi(); }
    else if (e.key === 'Enter') { if (supplierHighlight >= 0 && supplierFiltered[supplierHighlight]) { e.preventDefault(); selectSupplier(supplierFiltered[supplierHighlight]); } }
    else if (e.key === 'Escape') { supplierDropdown.style.display = 'none'; }
});
function scrollHi() { const el = supplierResults.children[supplierHighlight]; if (el) el.scrollIntoView({ block: 'nearest' }); }

function selectSupplier(s) {
    document.getElementById('supplierId').value = s.id;
    document.getElementById('selectedSupplierName').textContent = s.name;
    document.getElementById('selectedSupplierCountry').textContent = s.country || '';
    supplierCard.style.display = 'flex';
    supplierSearch.style.display = 'none';
    supplierDropdown.style.display = 'none';
}

document.getElementById('clearSupplier').addEventListener('click', () => {
    document.getElementById('supplierId').value = '';
    supplierSearch.value = '';
    supplierSearch.style.display = '';
    supplierCard.style.display = 'none';
    supplierSearch.focus();
});

document.addEventListener('click', e => {
    if (!e.target.closest('#supplierSearch') && !e.target.closest('#supplierDropdown')) {
        supplierDropdown.style.display = 'none';
    }
});

// Quick-add supplier modal
const supplierModal = new bootstrap.Modal(document.getElementById('quickSupplierModal'));
document.getElementById('supplierAddBtn').addEventListener('mousedown', () => {
    document.getElementById('qsName').value = supplierSearch.value;
    document.getElementById('qsCountry').value = '';
    document.getElementById('qsPhone').value = '';
    document.getElementById('qsError').style.display = 'none';
    supplierDropdown.style.display = 'none';
    supplierModal.show();
});

document.getElementById('saveQuickSupplier').addEventListener('click', () => {
    const name  = document.getElementById('qsName').value.trim();
    const errEl = document.getElementById('qsError');
    const spin  = document.getElementById('qsSpinner');
    if (!name) { errEl.textContent = 'Name is required.'; errEl.style.display = 'block'; return; }

    spin.classList.remove('d-none');
    fetch('/api/suppliers/quick', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        body: JSON.stringify({ name, country: document.getElementById('qsCountry').value, phone: document.getElementById('qsPhone').value })
    })
    .then(r => r.json())
    .then(s => { spin.classList.add('d-none'); supplierModal.hide(); selectSupplier(s); })
    .catch(() => { spin.classList.add('d-none'); errEl.textContent = 'Error. Try again.'; errEl.style.display = 'block'; });
});

/* ── Z-code auto-exclude ── */
function checkZCode(val) {
    const prefix = (val.split('_')[0] || '').toUpperCase();
    if (prefix.length >= 2 && prefix.endsWith('Z')) {
        document.getElementById('excludedFromPromo').checked = true;
        document.getElementById('promoExcludeBox').classList.add('active');
    }
}

/* ── Live product code uniqueness check ── */
let codeCheckTimer = null;
function checkCodeUnique(val) {
    const warn = document.getElementById('codeUniqueWarn');
    const ok   = document.getElementById('codeUniqueOk');
    const msg  = document.getElementById('codeUniqueMsg');
    warn.style.display = 'none';
    ok.style.display   = 'none';
    if (!val || val.length < 2) return;
    clearTimeout(codeCheckTimer);
    codeCheckTimer = setTimeout(() => {
        fetch(`/api/products/check-code?code=${encodeURIComponent(val)}&trip_id=${document.querySelector('[name=trip_id]')?.value || ''}`)
            .then(r => r.json())
            .then(data => {
                if (data.exists) {
                    msg.textContent = `Already used by: ${data.product_code} (${data.trip_name})`;
                    warn.style.display = 'block';
                    ok.style.display   = 'none';
                } else {
                    ok.style.display   = 'block';
                    warn.style.display = 'none';
                }
            })
            .catch(() => {});
    }, 400);
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
                <input type="number" name="variants[${variantIndex}][price_adjustment]"
                    class="form-control" placeholder="Extra price for this variant (Rp)" value="0" step="1">
                <span class="input-group-text text-muted">Rp extra</span>
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