@extends('layouts.app')
@section('title', 'Edit: ' . $product->name)
@section('page-title', 'Edit: ' . $product->name)

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
<div class="d-flex gap-2 mb-3">
    <a href="{{ route('products.show', $product) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>
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
                oninput="this.value=this.value.toUpperCase(); checkZCode(this.value); checkCodeUnique(this.value, {{ $product->id }})">
            <div class="form-text">Prefix ending in Z → auto-excludes from promos</div>
            <div id="codeUniqueWarn" class="text-danger small mt-1" style="display:none;">
                <i class="bi bi-x-circle-fill me-1"></i><span id="codeUniqueMsg"></span>
            </div>
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
        <div class="col-md-4">
            <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
            <input type="hidden" name="supplier_id" id="supplierId" value="{{ old('supplier_id', $product->supplier_id) }}" required>

            {{-- Selected supplier card --}}
            <div id="selectedSupplierCard"
                style="{{ $product->supplier ? 'display:flex' : 'display:none' }};background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:.5rem .9rem;align-items:center;justify-content:space-between;">
                <div>
                    <span class="fw-semibold small" id="selectedSupplierName">{{ $product->supplier?->name ?? '' }}</span>
                    <span class="text-muted small ms-2" id="selectedSupplierCountry">{{ $product->supplier?->country ?? '' }}</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="clearSupplier">×</button>
            </div>

            {{-- Search input --}}
            <div class="position-relative" id="supplierSearchWrap" style="{{ $product->supplier ? 'display:none' : '' }}">
                <input type="text" id="supplierSearch" class="form-control" placeholder="Type to search supplier…" autocomplete="off">
                <div id="supplierDropdown" style="display:none;position:absolute;z-index:1050;width:100%;background:#fff;border:1px solid #dee2e6;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;">
                    <div id="supplierResults"></div>
                    <div id="supplierAddBtn" style="padding:.65rem 1rem;cursor:pointer;color:#2563eb;font-weight:600;font-size:.85rem;border-top:2px solid #e5e7eb;">
                        <i class="bi bi-plus-circle me-1"></i>Add new supplier…
                    </div>
                </div>
            </div>
            <div id="supplierRequiredMsg" class="text-danger small mt-1" style="display:none;">
        <i class="bi bi-exclamation-circle-fill me-1"></i>Supplier is required before saving.
    </div>
    <div class="form-text"><a href="{{ route('suppliers.index') }}" target="_blank">Manage all suppliers</a></div>
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
            <label class="form-label fw-semibold">Price (Rp) <span class="text-danger">*</span></label>
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
                    value="{{ old('weight_gram', $product->weight_gram) }}" min="0" step="1"
                    oninput="document.getElementById('weightWarnMsg').style.display = this.value == 0 ? 'block' : 'none'">
                <span class="input-group-text text-muted">gram</span>
            </div>
            <div id="weightWarnMsg" class="text-warning small mt-1" style="{{ $product->weight_gram == 0 ? '' : 'display:none' }}">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>0g — shipping won't calculate for orders with this product.
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
</div>

</form>

{{-- Delete form OUTSIDE the edit form to prevent nested form bug --}}
<form method="POST" action="{{ route('products.destroy', $product) }}"
    onsubmit="return confirm('Delete this product and all its variants?')">
    @csrf @method('DELETE')
    <button type="submit" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-trash3 me-1"></i>Delete this product
    </button>
</form>
</div>
</div>
@endsection

@push('scripts')
<script>
document.querySelector('form').addEventListener('submit', function(e) {
    const supplierId = document.getElementById('supplierId').value;
    const msg = document.getElementById('supplierRequiredMsg');
    if (!supplierId) {
        e.preventDefault();
        msg.style.display = 'block';
        document.getElementById('supplierId').closest('.col-md-4').scrollIntoView({behavior:'smooth'});
    }
});

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

let codeCheckTimer = null;
function checkCodeUnique(val, excludeId) {
    const warn = document.getElementById('codeUniqueWarn');
    const msg  = document.getElementById('codeUniqueMsg');
    warn.style.display = 'none';
    if (!val || val.length < 2) return;
    clearTimeout(codeCheckTimer);
    codeCheckTimer = setTimeout(() => {
        fetch(`/api/products/check-code?code=${encodeURIComponent(val)}&trip_id={{ $product->trip_id }}&exclude=${excludeId || ''}`)
            .then(r => r.json())
            .then(data => {
                if (data.exists) {
                    msg.textContent = `Already used by: ${data.product_name} (${data.trip_name})`;
                    warn.style.display = 'block';
                }
            }).catch(() => {});
    }, 400);
}

/* ── Supplier search ── */
let supplierTimeout = null;
const supplierSearch   = document.getElementById('supplierSearch');
const supplierDropdown = document.getElementById('supplierDropdown');
const supplierResults  = document.getElementById('supplierResults');
const supplierCard     = document.getElementById('selectedSupplierCard');
const supplierWrap     = document.getElementById('supplierSearchWrap');

supplierSearch?.addEventListener('input', function () {
    clearTimeout(supplierTimeout);
    const q = this.value.trim();
    supplierTimeout = setTimeout(() => {
        fetch(`/api/suppliers/search?q=${encodeURIComponent(q)}`)
            .then(r => r.json())
            .then(data => {
                supplierResults.innerHTML = '';
                data.length
                    ? data.forEach(s => {
                        const d = document.createElement('div');
                        d.style.cssText = 'padding:.55rem 1rem;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:.875rem;';
                        d.innerHTML = `<div style="font-weight:600;">${s.name}</div><div style="font-size:.75rem;color:#6b7280;">${s.country||''}${s.phone?' · '+s.phone:''}</div>`;
                        d.addEventListener('mousedown', () => selectSupplier(s));
                        supplierResults.appendChild(d);
                    })
                    : (supplierResults.innerHTML = '<div style="padding:.6rem 1rem;color:#94a3b8;font-size:.85rem;">No suppliers found</div>');
                supplierDropdown.style.display = 'block';
            });
    }, 200);
});

function selectSupplier(s) {
    document.getElementById('supplierId').value = s.id;
    document.getElementById('selectedSupplierName').textContent = s.name;
    document.getElementById('selectedSupplierCountry').textContent = s.country || '';
    supplierCard.style.display = 'flex';
    if (supplierWrap) supplierWrap.style.display = 'none';
    supplierDropdown.style.display = 'none';
}

document.getElementById('clearSupplier')?.addEventListener('click', () => {
    document.getElementById('supplierId').value = '';
    supplierCard.style.display = 'none';
    if (supplierWrap) supplierWrap.style.display = '';
    supplierSearch?.focus();
});

document.addEventListener('click', e => {
    if (!e.target.closest('#supplierSearch') && !e.target.closest('#supplierDropdown')) {
        if (supplierDropdown) supplierDropdown.style.display = 'none';
    }
});

const supplierModal = new bootstrap.Modal(document.getElementById('quickSupplierModal'));
document.getElementById('supplierAddBtn')?.addEventListener('mousedown', () => {
    document.getElementById('qsName').value = supplierSearch?.value || '';
    document.getElementById('qsError').style.display = 'none';
    supplierDropdown.style.display = 'none';
    supplierModal.show();
});
document.getElementById('saveQuickSupplier')?.addEventListener('click', () => {
    const name=document.getElementById('qsName').value.trim();
    const errEl=document.getElementById('qsError');
    const spin=document.getElementById('qsSpinner');
    if(!name){errEl.textContent='Name required.';errEl.style.display='block';return;}
    spin.classList.remove('d-none');
    fetch('/api/suppliers/quick',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({name,country:document.getElementById('qsCountry').value,phone:document.getElementById('qsPhone').value})})
    .then(r=>r.json()).then(s=>{spin.classList.add('d-none');supplierModal.hide();selectSupplier(s);})
    .catch(()=>{spin.classList.add('d-none');errEl.textContent='Error.';errEl.style.display='block';});
});
</script>
@endpush

<div class="modal fade" id="quickSupplierModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-building me-2"></i>Add New Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label fw-semibold">Supplier Name <span class="text-danger">*</span></label><input type="text" id="qsName" class="form-control"></div>
            <div class="mb-3"><label class="form-label fw-semibold">Country</label><input type="text" id="qsCountry" class="form-control" list="qsCountryList"><datalist id="qsCountryList"><option value="China"><option value="Korea"><option value="Japan"><option value="Thailand"></datalist></div>
            <div class="mb-3"><label class="form-label fw-semibold">Phone / WeChat</label><input type="text" id="qsPhone" class="form-control"></div>
            <div id="qsError" class="text-danger small" style="display:none;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveQuickSupplier"><span id="qsSpinner" class="spinner-border spinner-border-sm me-1 d-none"></span>Save</button>
        </div>
    </div></div>
</div>
