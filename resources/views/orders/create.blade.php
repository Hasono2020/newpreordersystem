@extends('layouts.app')
@section('title', 'New Order')
@section('page-title', 'New Order')

@push('styles')
<style>
.item-row { background:#f9fafb; border-radius:8px; padding:.75rem 1rem; margin-bottom:.5rem; border:1px solid #e5e7eb; transition:border-color .15s; }
.item-row.active-row { border-color:#2563eb; background:#f0f9ff; }

/* variant tags */
.var-tags { display:flex; flex-wrap:wrap; gap:4px; margin-top:4px; min-height:28px; }
.var-tag { border:1px solid #d1d5db; border-radius:4px; padding:2px 8px; font-size:.78rem; cursor:pointer;
    background:#fff; color:#374151; transition:all .1s; user-select:none; }
.var-tag:hover { border-color:#93c5fd; background:#eff6ff; }
.var-tag.selected { border-color:#2563eb; background:#2563eb; color:#fff; font-weight:500; }
.var-tag:focus { outline:2px solid #2563eb; outline-offset:1px; }
.no-variant-badge { font-size:.75rem; color:#9ca3af; padding:4px 0; }

/* ── Phone-first customer lookup ── */
#phoneInput { font-family: monospace; letter-spacing:.05em; }
#phoneInput.is-valid-phone  { border-color:#198754; background-color:#f0fdf4; }
#phoneInput.is-invalid-phone { border-color:#dc3545; }
#phoneSpinner { display:none; }
#customerFoundCard { display:none; background:#f0fdf4; border:1.5px solid #86efac; border-radius:8px; padding:.65rem 1rem; }
#customerNotFound  { display:none; }
#inlineQuickAdd    { background:#fff8f0; border:1px solid #fed7aa; border-radius:8px; padding:1rem; margin-top:.5rem; }
#nameSearch { display:none; }
#nameDropdown { position:absolute; z-index:1050; background:#fff; border:1px solid #dee2e6; border-radius:8px;
    box-shadow:0 4px 16px rgba(0,0,0,.12); width:100%; max-height:240px; overflow-y:auto; display:none; }
#nameDropdown .cust-item { padding:.55rem 1rem; cursor:pointer; border-bottom:1px solid #f3f4f6; }
#nameDropdown .cust-item:hover { background:#f0f9ff; }
#nameDropdown .cust-item .cust-name { font-weight:600; font-size:.88rem; }
#nameDropdown .cust-item .cust-meta { font-size:.75rem; color:#6b7280; font-family:monospace; }

/* ── Product search ── */
.product-dropdown { position:absolute; z-index:1060; background:#fff; border:1px solid #dee2e6; border-radius:6px;
    box-shadow:0 4px 12px rgba(0,0,0,.12); width:100%; max-height:220px; overflow-y:auto; display:none; top:100%; left:0; }
.product-dropdown .prod-item { padding:.45rem .75rem; cursor:pointer; border-bottom:1px solid #f3f4f6; font-size:.82rem; }
.product-dropdown .prod-item:hover { background:#f0f9ff; }
.product-dropdown .prod-item .prod-code { font-weight:700; color:#2563eb; margin-right:.35rem; }
.product-dropdown .prod-no-result { padding:.5rem .75rem; color:#9ca3af; font-size:.82rem; }
.product-selected-badge { background:#eff6ff; border:1px solid #bfdbfe; border-radius:5px; padding:.3rem .7rem;
    font-size:.82rem; display:flex; align-items:center; justify-content:space-between; }
.product-selected-badge .clear-product { cursor:pointer; color:#6b7280; font-size:.8rem; margin-left:.5rem;
    padding:1px 5px; border:1px solid #d1d5db; border-radius:3px; background:#fff; }
.product-selected-badge .clear-product:hover { color:#dc2626; border-color:#fca5a5; background:#fff5f5; }

/* Keyboard hint */
.kbd-hint { display:inline-block; background:#f3f4f6; border:1px solid #d1d5db; border-radius:3px;
    padding:0 4px; font-size:.7rem; font-family:monospace; color:#6b7280; }
</style>
@endpush

@section('content')
<div class="row justify-content-center">
<div class="col-lg-10">
<form method="POST" action="{{ route('orders.store') }}" id="orderForm">
    @csrf

    {{-- ── Customer Card ── --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 fw-semibold">
            <i class="bi bi-person me-2"></i>Customer & Delivery
        </div>
        <div class="card-body">

            {{-- Hidden real fields --}}
            <input type="hidden" name="customer_id"       id="customerId">
            <input type="hidden" name="shipping_area_id"  id="shippingAreaSelect">

            {{-- ── Step 1: Phone lookup (primary path) ── --}}
            <div class="row g-3 align-items-start mb-2">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">
                        Phone number <span class="text-danger">*</span>
                        <span class="text-muted fw-normal small ms-1">(fastest lookup)</span>
                    </label>
                    <div class="position-relative">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-phone"></i></span>
                            <input type="text" id="phoneInput" class="form-control"
                                   placeholder="08xxxxxxxxx" autocomplete="off" inputmode="numeric" maxlength="15">
                            <span class="input-group-text" id="phoneSpinner">
                                <span class="spinner-border spinner-border-sm text-secondary"></span>
                            </span>
                        </div>
                        <div id="phoneDropdown" style="position:absolute;z-index:1050;background:#fff;border:1px solid #dee2e6;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);width:100%;max-height:240px;overflow-y:auto;display:none;top:100%;left:0;">
                            <div id="phoneResults"></div>
                        </div>
                    </div>
                    <div class="form-text text-muted" style="font-size:.72rem;">
                        Type 3+ chars to search &middot; <span class="kbd-hint">Tab</span> on full number for exact lookup
                    </div>
                </div>

                {{-- Customer found: show green pill --}}
                <div class="col-md-8 d-flex align-items-end" id="customerFoundCol" style="display:none!important;">
                    <div id="customerFoundCard" class="w-100">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle bg-success bg-opacity-10 text-success fw-bold d-flex align-items-center justify-content-center" style="width:38px;height:38px;font-size:.85rem;" id="customerAvatar">??</div>
                                <div>
                                    <div class="fw-semibold" id="selectedCustomerName"></div>
                                    <div class="small text-muted">
                                        <span id="selectedCustomerPhone" class="font-monospace"></span>
                                        &nbsp;·&nbsp; <span id="selectedCustomerType"></span>
                                        <span id="selectedCustomerArea" class="ms-1"></span>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearCustomer">
                                <i class="bi bi-arrow-repeat me-1"></i>Change
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Step 2a: Not found → inline quick-add ── --}}
            <div id="customerNotFound">
                <div class="alert alert-warning py-2 px-3 small mb-2">
                    <i class="bi bi-person-x me-1"></i>
                    No customer with that phone number.
                    <strong>Fill details below to add new customer</strong>, or
                    <a href="#" id="switchToNameSearch">search by name instead</a>.
                </div>
                <div id="inlineQuickAdd">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Full name <span class="text-danger">*</span></label>
                            <input type="text" id="qcName" class="form-control form-control-sm" placeholder="Customer name" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Type</label>
                            <select id="qcType" class="form-select form-select-sm">
                                <option value="customer">Customer</option>
                                <option value="reseller">Reseller</option>
                                <option value="selected_customer">Selected</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Shipping area <span class="text-danger">*</span></label>
                            <select id="qcShippingArea" class="form-select form-select-sm">
                                <option value="">— Select area —</option>
                                @foreach($shippingAreas as $area)
                                    <option value="{{ $area->id }}"
                                        data-price="{{ $area->price_per_kg }}"
                                        data-name="{{ $area->name }}">
                                        {{ $area->name }}{{ $area->province ? ' ('.$area->province.')' : '' }}
                                        — Rp {{ number_format($area->price_per_kg, 0, ',', '.') }}/kg
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Address <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" id="qcAddress" class="form-control form-control-sm" placeholder="Shipping address…">
                        </div>
                        <div class="col-md-6 d-flex align-items-end gap-2">
                            <button type="button" class="btn btn-sm btn-primary w-100" id="saveQuickCustomer">
                                <span id="qcSpinner" class="spinner-border spinner-border-sm me-1 d-none"></span>
                                <i class="bi bi-person-plus me-1"></i>Save & continue
                                <span class="kbd-hint ms-1">Enter</span>
                            </button>
                        </div>
                        <div class="col-12">
                            <div id="qcError" class="text-danger small" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Step 2b: Name search fallback ── --}}
            <div id="nameSearch" class="position-relative">
                <label class="form-label small fw-semibold">Search by name</label>
                <input type="text" id="nameSearchInput" class="form-control form-control-sm"
                       placeholder="Type name to search…" autocomplete="off">
                <div id="nameDropdown">
                    <div id="nameResults"></div>
                </div>
                <div class="form-text text-muted" style="font-size:.72rem;">
                    Or <a href="#" id="switchToPhoneSearch">go back to phone lookup</a>
                </div>
            </div>

            {{-- Shipping info (shown once customer selected) --}}
            <div class="mt-2 p-2 bg-light rounded small" id="shippingCalcBox" style="display:none;">
                <i class="bi bi-truck me-1 text-primary"></i>
                Shipping: <strong id="shippingAreaName"></strong>
                &nbsp;·&nbsp; Est. fee: <strong id="shippingFeeDisplay">Rp 0</strong>
                <span class="text-muted ms-2" id="shippingWeightNote"></span>
            </div>

        </div>
    </div>

    {{-- ── Trip & Notes ── --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 fw-semibold">
            <i class="bi bi-airplane me-2"></i>Trip & Notes
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Trip <span class="text-danger">*</span></label>
                    <select name="trip_id" id="tripSelect" class="form-select" required>
                        <option value="">Select trip…</option>
                        @foreach($trips as $trip)
                            <option value="{{ $trip->id }}"
                                {{ old('trip_id', $selectedTrip?->id) == $trip->id ? 'selected' : '' }}>
                                {{ $trip->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">
                        Order Date &amp; Time
                        <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;">FIFO</span>
                    </label>
                    <input type="datetime-local" name="ordered_at" class="form-control"
                        value="{{ old('ordered_at', now()->format('Y-m-d\TH:i')) }}">
                    <div class="form-text text-muted" style="font-size:.72rem;">
                        <i class="bi bi-clock me-1"></i>Adjust for missed orders.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Customer Service</label>
                    <select name="cs_agent_id" id="csAgentSelect" class="form-select">
                        <option value="">— none —</option>
                        @foreach($csAgents as $cs)
                            <option value="{{ $cs->id }}" {{ old('cs_agent_id') == $cs->id ? 'selected' : '' }}>
                                {{ $cs->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text text-muted" style="font-size:.72rem;">
                        <i class="bi bi-headset me-1"></i>Who handled the livechat.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Notes</label>
                    <input type="text" name="notes" class="form-control" value="{{ old('notes') }}" placeholder="Special instructions…">
                </div>
            </div>
        </div>
    </div>

    {{-- ── Order Items ── --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Order Items</span>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small"><span class="kbd-hint">Alt+N</span> add item</span>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                    <i class="bi bi-plus-lg me-1"></i>Add Item
                </button>
            </div>
        </div>
        <div class="card-body" id="itemsContainer">
            <div class="item-row" data-index="0">
                <div class="d-flex gap-2 align-items-start">
                    {{-- Product search --}}
                    <div style="flex:0 0 240px;">
                        <div class="small text-muted mb-1">Product</div>
                        <input type="hidden" name="items[0][product_id]" class="product-id-input">
                        <input type="hidden" name="items[0][product_variant_id]" class="variant-id-input">
                        <input type="hidden" name="items[0][unit_price]" class="item-price" value="0">
                        <div class="product-search-wrap position-relative">
                            <input type="text" class="form-control form-control-sm product-search-input"
                                   placeholder="Code or name…" autocomplete="off">
                            <div class="product-dropdown"></div>
                        </div>
                        <div class="product-selected-badge mt-1" style="display:none;"></div>
                    </div>
                    {{-- Variant tags --}}
                    <div style="flex:1;min-width:0;">
                        <div class="small text-muted mb-1">Variant <span class="kbd-hint" style="font-size:.65rem;">↑↓ navigate · Enter select</span></div>
                        <div class="var-tags">
                            <span class="no-variant-badge">— select product first —</span>
                        </div>
                    </div>
                    {{-- Qty --}}
                    <div style="flex:0 0 72px;">
                        <div class="small text-muted mb-1">Qty</div>
                        <input type="number" name="items[0][quantity]" class="form-control form-control-sm item-qty text-center" value="1" min="1" required>
                    </div>
                    {{-- Remove --}}
                    <div style="flex:0 0 32px;margin-top:20px;">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-item w-100 px-0">×</button>
                    </div>
                </div>
                <div class="small text-muted mt-1 ps-1">
                    Line: <strong class="line-total">Rp 0</strong>
                    &nbsp;·&nbsp; <span class="line-weight">0g</span>
                    &nbsp;·&nbsp; <span class="product-code text-info font-monospace">—</span>
                    <span class="weight-warn text-warning ms-1" style="display:none;">
                        <i class="bi bi-exclamation-triangle-fill"></i> Weight is 0
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Summary ── --}}
    <div id="zeroPriceWarning" class="alert alert-warning py-2 small mb-2" style="display:none;">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>Some items have a price of Rp 0.</strong> Please check the product's selling price or enter the price manually.
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row justify-content-end">
                <div class="col-md-5">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted">Subtotal</td><td class="text-end fw-semibold" id="displaySubtotal">Rp 0</td></tr>
                        <tr><td class="text-muted">Total Weight</td><td class="text-end small" id="displayWeight">0 g → 0 kg charged</td></tr>
                        <tr><td class="text-muted">Shipping Fee</td><td class="text-end" id="displayShipping">Rp 0</td></tr>
                        <tr class="text-success"><td>Promo / Discount</td><td class="text-end small text-muted">(applied on save)</td></tr>
                        <tr class="fw-bold border-top"><td>Estimated Total</td><td class="text-end fs-5" id="displayTotal">Rp 0</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4">Save Order</button>
        <a href="{{ route('orders.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
</div>
</div>
@endsection

@push('scripts')
<script>
// ── Helpers ──────────────────────────────────────────────────────────
let tripProducts  = [];
let itemIndex     = 1;
let phoneTimer    = null;
let nameTimer     = null;

function fmt(n)    { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }
function fmtG(g)   { return g >= 1000 ? (g/1000).toFixed(2).replace(/\.?0+$/,'') + ' kg' : g + ' g'; }
function calcKg(g) { if(g<=0) return 0; if(g<=1350) return 1; return Math.ceil((g-350)/1000); }
function initials(name) {
    const parts = name.trim().split(' ');
    return parts.length >= 2 ? (parts[0][0] + parts[1][0]).toUpperCase() : name.slice(0,2).toUpperCase();
}

// ── Trip products ────────────────────────────────────────────────────
document.getElementById('tripSelect').addEventListener('change', function() {
    const id = this.value;
    if (!id) { tripProducts=[]; return; }
    fetch(`/api/trips/${id}/products`).then(r=>r.json()).then(data => { tripProducts = data; });
});

// ── CUSTOMER: Phone-first lookup ─────────────────────────────────────
const phoneInput       = document.getElementById('phoneInput');
const phoneSpinnerEl   = document.getElementById('phoneSpinner');
const customerFoundCol = document.getElementById('customerFoundCol');
const customerFoundCard= document.getElementById('customerFoundCard');
const customerNotFound = document.getElementById('customerNotFound');
const nameSearchDiv    = document.getElementById('nameSearch');

phoneInput.focus();

function showCustomerFound(c) {
    document.getElementById('customerId').value = c.id;
    document.getElementById('selectedCustomerName').textContent  = c.name;
    document.getElementById('selectedCustomerPhone').textContent = c.phone || '';
    document.getElementById('selectedCustomerType').textContent  = (c.type||'').replace(/_/g,' ');
    document.getElementById('customerAvatar').textContent        = initials(c.name);
    customerFoundCard.style.display  = 'flex';
    customerFoundCol.style.display   = 'flex';
    customerNotFound.style.display   = 'none';
    nameSearchDiv.style.display      = 'none';
    phoneInput.classList.add('is-valid-phone');
    if (c.default_shipping_area_id) applyShippingArea(c.default_shipping_area_id);
}

function clearCustomerState() {
    document.getElementById('customerId').value = '';
    document.getElementById('shippingAreaSelect').value = '';
    customerFoundCard.style.display  = 'none';
    customerFoundCol.style.display   = 'none';
    customerNotFound.style.display   = 'none';
    nameSearchDiv.style.display      = 'none';
    phoneInput.classList.remove('is-valid-phone','is-invalid-phone');
    document.getElementById('shippingCalcBox').style.display = 'none';
    document.getElementById('selectedCustomerArea').textContent = '';
}

// Lookup on Tab / blur (full number)
phoneInput.addEventListener('blur', doPhoneLookup);
phoneInput.addEventListener('keydown', e => {
    if (e.key === 'Tab') { e.preventDefault(); doPhoneLookup(); }
});

function doPhoneLookup() {
    const q = phoneInput.value.trim();
    if (!q) return;
    phoneSpinnerEl.style.display = 'inline-flex';
    fetch(`/api/customers/search?q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => {
            phoneSpinnerEl.style.display = 'none';
            // Exact phone match
            const exact = data.find(c => (c.phone||'').replace(/\D/g,'') === q.replace(/\D/g,''));
            if (exact) {
                showCustomerFound(exact);
                // Move focus to trip if already set, else trip select
                const nextFocus = document.getElementById('tripSelect').value
                    ? document.querySelector('.product-search-input')
                    : document.getElementById('tripSelect');
                nextFocus?.focus();
            } else if (q.length >= 5) {
                // Not found → show inline quick-add with phone pre-filled
                phoneInput.classList.add('is-invalid-phone');
                customerNotFound.style.display = 'block';
                document.getElementById('qcName').focus();
            }
        })
        .catch(() => { phoneSpinnerEl.style.display = 'none'; });
}

// Live fuzzy search while typing
phoneInput.addEventListener('input', function() {
    clearTimeout(phoneTimer);
    const q = this.value.trim();
    const dd  = document.getElementById('phoneDropdown');
    const res = document.getElementById('phoneResults');
    if (q.length < 3) { dd.style.display='none'; return; }
    phoneTimer = setTimeout(() => {
        fetch(`/api/customers/search?q=${encodeURIComponent(q)}`).then(r=>r.json()).then(data => {
            if (document.getElementById('customerId').value) return;
            if (!data.length) { dd.style.display='none'; return; }
            res.innerHTML = '';
            data.slice(0,8).forEach(c => {
                const div = document.createElement('div');
                div.className = 'cust-item';
                div.style.cssText = 'padding:.55rem 1rem;cursor:pointer;border-bottom:1px solid #f3f4f6;';
                div.innerHTML = `<div style="font-weight:600;font-size:.88rem">${c.name}</div>
                    <div style="font-size:.75rem;color:#6b7280;font-family:monospace">${c.phone||'No phone'} · ${(c.type||'').replace(/_/g,' ')}</div>`;
                div.addEventListener('mousedown', e => { e.preventDefault(); dd.style.display='none'; phoneInput.value = c.phone||''; showCustomerFound(c); });
                div.addEventListener('mouseover', () => div.style.background='#f0f9ff');
                div.addEventListener('mouseout',  () => div.style.background='');
                res.appendChild(div);
            });
            dd.style.display = 'block';
        });
    }, 200);
});

document.getElementById('clearCustomer').addEventListener('click', () => {
    clearCustomerState();
    phoneInput.value = '';
    phoneInput.focus();
});

// Switch to name search
document.getElementById('switchToNameSearch').addEventListener('click', e => {
    e.preventDefault();
    customerNotFound.style.display = 'none';
    nameSearchDiv.style.display = 'block';
    document.getElementById('nameSearchInput').focus();
});

document.getElementById('switchToPhoneSearch').addEventListener('click', e => {
    e.preventDefault();
    nameSearchDiv.style.display = 'none';
    clearCustomerState();
    phoneInput.value = '';
    phoneInput.focus();
});

// Name search fallback
document.getElementById('nameSearchInput').addEventListener('input', function() {
    clearTimeout(nameTimer);
    const q = this.value.trim();
    if (!q) { document.getElementById('nameDropdown').style.display='none'; return; }
    nameTimer = setTimeout(() => {
        fetch(`/api/customers/search?q=${encodeURIComponent(q)}`).then(r=>r.json()).then(data => {
            const res = document.getElementById('nameResults');
            const dd  = document.getElementById('nameDropdown');
            res.innerHTML = '';
            if (!data.length) { dd.style.display='none'; return; }
            data.slice(0,10).forEach(c => {
                const div = document.createElement('div');
                div.className = 'cust-item';
                div.innerHTML = `<div class="cust-name">${c.name}</div>
                    <div class="cust-meta">${c.phone||'No phone'} · ${(c.type||'').replace(/_/g,' ')}</div>`;
                div.addEventListener('click', () => {
                    dd.style.display = 'none';
                    nameSearchDiv.style.display = 'none';
                    phoneInput.value = c.phone || '';
                    showCustomerFound(c);
                });
                res.appendChild(div);
            });
            dd.style.display = 'block';
        });
    }, 200);
});

document.addEventListener('click', e => {
    if (!e.target.closest('#phoneInput') && !e.target.closest('#phoneDropdown'))
        document.getElementById('phoneDropdown').style.display = 'none';
});

// ── Inline quick-add customer ────────────────────────────────────────
document.getElementById('saveQuickCustomer').addEventListener('click', saveInlineCustomer);
document.getElementById('inlineQuickAdd').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); saveInlineCustomer(); }
});

function saveInlineCustomer() {
    const name    = document.getElementById('qcName').value.trim();
    const phone   = phoneInput.value.trim();
    const type    = document.getElementById('qcType').value;
    const address = document.getElementById('qcAddress').value.trim();
    const areaSel = document.getElementById('qcShippingArea');
    const areaId  = areaSel.value;
    const errEl   = document.getElementById('qcError');
    const spin    = document.getElementById('qcSpinner');

    if (!name)   { errEl.textContent='Name is required.'; errEl.style.display='block'; document.getElementById('qcName').focus(); return; }
    if (!phone)  { errEl.textContent='Phone is required — fill phone field above.'; errEl.style.display='block'; phoneInput.focus(); return; }
    if (!areaId) { errEl.textContent='Shipping area is required.'; errEl.style.display='block'; areaSel.focus(); return; }
    errEl.style.display = 'none';

    spin.classList.remove('d-none');
    document.getElementById('saveQuickCustomer').disabled = true;

    fetch('/api/customers/quick', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content},
        body: JSON.stringify({name, phone, type, address, default_shipping_area_id: areaId})
    })
    .then(r => r.json())
    .then(data => {
        spin.classList.add('d-none');
        document.getElementById('saveQuickCustomer').disabled = false;
        if (data.duplicate) {
            if (confirm(`⚠️ ${data.message}\n\nUse this existing customer instead?`)) {
                customerNotFound.style.display = 'none';
                showCustomerFound(data.customer);
                if (areaId) applyShippingArea(areaId);
            } else {
                errEl.textContent = 'Please use a different phone number.';
                errEl.style.display = 'block';
            }
            return;
        }
        const c = data.customer ?? data;
        customerNotFound.style.display = 'none';
        showCustomerFound(c);
        if (areaId) applyShippingArea(areaId);
        // Move focus to trip or first product field
        const nextFocus = document.getElementById('tripSelect').value
            ? document.querySelector('.product-search-input')
            : document.getElementById('tripSelect');
        nextFocus?.focus();
    })
    .catch(() => {
        spin.classList.add('d-none');
        document.getElementById('saveQuickCustomer').disabled = false;
        errEl.textContent = 'Error saving. Try again.';
        errEl.style.display = 'block';
    });
}

// ── Shipping area ────────────────────────────────────────────────────
function applyShippingArea(areaId) {
    const hiddenArea = document.getElementById('shippingAreaSelect');
    const areaSel    = document.getElementById('qcShippingArea');
    if (hiddenArea) hiddenArea.value = areaId;

    let areaName = '', pricePerKg = 0;
    if (areaSel) {
        for (const opt of areaSel.options) {
            if (opt.value == areaId) {
                areaName   = opt.dataset.name || opt.text.split('—')[0].trim();
                pricePerKg = parseFloat(opt.dataset.price || 0);
                break;
            }
        }
    }

    const areaEl = document.getElementById('selectedCustomerArea');
    if (areaEl && areaName) areaEl.textContent = '· 🚚 ' + areaName;

    const calcBox    = document.getElementById('shippingCalcBox');
    const areaNameEl = document.getElementById('shippingAreaName');
    if (calcBox)    calcBox.style.display = pricePerKg > 0 ? 'block' : 'none';
    if (areaNameEl) areaNameEl.textContent = areaName;

    recalc();
}

// ── Product search ───────────────────────────────────────────────────
function initProductSearch(wrap) {
    const searchInput = wrap.querySelector('.product-search-input');
    const dropdown    = wrap.querySelector('.product-dropdown');
    const row         = wrap.closest('.item-row');

    searchInput.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        dropdown.innerHTML = '';
        if (!q) { dropdown.style.display='none'; return; }

        const matches = tripProducts.filter(p =>
            (p.code && p.code.toLowerCase().includes(q)) ||
            (p.name && p.name.toLowerCase().includes(q))
        );

        if (!matches.length) {
            dropdown.innerHTML = '<div class="prod-no-result">No products found</div>';
            dropdown.style.display = 'block';
            return;
        }
        matches.slice(0,12).forEach(p => {
            const div = document.createElement('div');
            div.className = 'prod-item';
            div.innerHTML = `${p.code ? `<span class="prod-code">[${p.code}]</span>` : ''}<span>${p.name}</span><span style="float:right;color:#6b7280;font-size:.75rem">Rp ${parseInt(p.price).toLocaleString('id-ID')}</span>`;
            div.addEventListener('mousedown', e => { e.preventDefault(); selectProduct(p, wrap); });
            dropdown.appendChild(div);
        });

        // If there's an exact code match, highlight first result
        const exact = matches.find(p => p.code && p.code.toLowerCase() === q);
        if (exact && matches.length === 1) {
            selectProduct(exact, wrap);
            return;
        }

        dropdown.style.display = 'block';
    });

    // Arrow key navigation in dropdown
    searchInput.addEventListener('keydown', function(e) {
        const items = dropdown.querySelectorAll('.prod-item');
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const active = dropdown.querySelector('.prod-item.active');
            const next = active ? (active.nextElementSibling || items[0]) : items[0];
            active?.classList.remove('active'); next.classList.add('active');
            next.style.background = '#f0f9ff';
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const active = dropdown.querySelector('.prod-item.active');
            const prev = active ? (active.previousElementSibling || items[items.length-1]) : items[items.length-1];
            active?.classList.remove('active'); prev.classList.add('active');
            prev.style.background = '#f0f9ff';
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const active = dropdown.querySelector('.prod-item.active');
            if (active) active.dispatchEvent(new MouseEvent('mousedown'));
        }
    });

    searchInput.addEventListener('blur', () => setTimeout(() => { dropdown.style.display='none'; }, 150));
}

function selectProduct(p, wrap) {
    const row         = wrap.closest('.item-row');
    const hiddenInput = row.querySelector('.product-id-input');
    const varIdInput  = row.querySelector('.variant-id-input');
    const priceIn     = row.querySelector('.item-price');
    const badge       = row.querySelector('.product-selected-badge');
    const searchInput = wrap.querySelector('.product-search-input');
    const dropdown    = wrap.querySelector('.product-dropdown');
    const varTagsEl   = row.querySelector('.var-tags');
    const codeEl      = row.querySelector('.product-code');
    const weightWarn  = row.querySelector('.weight-warn');

    hiddenInput.value = p.id;
    varIdInput.value  = '';
    priceIn.value     = parseFloat(p.price) || 0;
    if (codeEl) codeEl.textContent = p.code || '—';
    if (weightWarn) weightWarn.style.display = (!p.weight || p.weight == 0) && p.id ? 'inline' : 'none';

    // Lock in product as badge
    const label = p.code ? `[${p.code}] ${p.name}` : p.name;
    badge.innerHTML = `<span class="font-monospace" style="font-size:.75rem;color:#2563eb">${p.code ? '['+p.code+']' : ''}</span>
        <span class="ms-1">${p.name}</span>
        <button type="button" class="clear-product ms-auto" title="Change product">change</button>`;
    badge.style.display = 'flex';
    badge.style.alignItems = 'center';
    searchInput.style.display = 'none';
    searchInput.value = '';
    dropdown.style.display = 'none';
    row.classList.add('active-row');

    badge.querySelector('.clear-product').addEventListener('click', () => {
        hiddenInput.value = '';
        varIdInput.value  = '';
        priceIn.value     = 0;
        badge.style.display = 'none';
        searchInput.style.display = '';
        searchInput.value = '';
        searchInput.focus();
        varTagsEl.innerHTML = '<span class="no-variant-badge">— select product first —</span>';
        row.classList.remove('active-row');
        if (codeEl) codeEl.textContent = '—';
        recalc();
    });

    // Build variant tag buttons
    const variants = p.variants || [];
    varTagsEl.innerHTML = '';

    if (!variants.length) {
        // No variants — show badge and jump to qty immediately
        varTagsEl.innerHTML = '<span class="no-variant-badge text-success"><i class="bi bi-check-circle me-1"></i>No variant</span>';
        setTimeout(() => row.querySelector('.item-qty')?.focus(), 50);
        checkDuplicate(row);
        recalc();
        return;
    }

    variants.forEach((v, i) => {
        const fp  = parseFloat(v.price) || parseFloat(p.price) || 0;
        const btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'var-tag';
        btn.textContent = v.label || 'Default';
        btn.dataset.varId    = v.id;
        btn.dataset.varPrice = fp;
        btn.tabIndex = 0;

        btn.addEventListener('click', () => selectVariantTag(btn, row));
        btn.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectVariantTag(btn, row); }
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                e.preventDefault();
                const next = btn.nextElementSibling;
                if (next && next.classList.contains('var-tag')) { next.focus(); }
            }
            if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                e.preventDefault();
                const prev = btn.previousElementSibling;
                if (prev && prev.classList.contains('var-tag')) { prev.focus(); }
            }
        });
        varTagsEl.appendChild(btn);
    });

    // Auto-select if only one variant
    if (variants.length === 1) {
        selectVariantTag(varTagsEl.querySelector('.var-tag'), row);
    } else {
        // Focus first tag for keyboard navigation
        setTimeout(() => varTagsEl.querySelector('.var-tag')?.focus(), 50);
    }
}

function selectVariantTag(btn, row) {
    const varTagsEl = row.querySelector('.var-tags');
    varTagsEl.querySelectorAll('.var-tag').forEach(t => t.classList.remove('selected'));
    btn.classList.add('selected');
    // Clear any validation error highlight and dismiss toast
    row.style.borderColor = '';
    varTagsEl.style.outline = '';
    document.getElementById('variantToast')?.remove();
    document.getElementById('variantToastBd')?.remove();

    const varIdInput = row.querySelector('.variant-id-input');
    const priceIn    = row.querySelector('.item-price');
    varIdInput.value = btn.dataset.varId;
    priceIn.value    = btn.dataset.varPrice;

    checkDuplicate(row);
    recalc();

    // Jump to qty
    setTimeout(() => row.querySelector('.item-qty')?.focus(), 40);
}

function getRowProduct(row) {
    const h = row.querySelector('.product-id-input');
    if (!h || !h.value) return null;
    return tripProducts.find(p => p.id == h.value) || null;
}

// ── Item events ──────────────────────────────────────────────────────
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('item-qty')) recalc();
});

function checkDuplicate(row) {
    const prodId = row.querySelector('.product-id-input')?.value;
    const varId  = row.querySelector('.variant-id-input')?.value || '';
    let warn = row.querySelector('.duplicate-warn');
    if (warn) warn.remove();
    if (!prodId) return;
    let dupRow = null;
    document.querySelectorAll('.item-row').forEach(r => {
        if (r === row) return;
        if (r.querySelector('.product-id-input')?.value === prodId && (r.querySelector('.variant-id-input')?.value||'') === varId) dupRow = r;
    });
    if (!dupRow) return;
    const warnDiv = document.createElement('div');
    warnDiv.className = 'duplicate-warn alert alert-warning py-2 px-3 mt-2 small';
    warnDiv.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i>This product+variant is already in another row.
        <button type="button" class="btn btn-sm btn-warning py-0 px-2 ms-2" onclick="mergeRow(this)">Merge quantities</button>`;
    row.appendChild(warnDiv);
}

function mergeRow(btn) {
    const row    = btn.closest('.item-row');
    const prodId = row.querySelector('.product-id-input')?.value;
    const varId  = row.querySelector('.variant-id-input')?.value || '';
    const qty    = parseInt(row.querySelector('.item-qty')?.value) || 1;
    let dupRow = null;
    document.querySelectorAll('.item-row').forEach(r => {
        if (r === row) return;
        if (r.querySelector('.product-id-input')?.value === prodId && (r.querySelector('.variant-id-input')?.value||'') === varId) dupRow = r;
    });
    if (dupRow) { dupRow.querySelector('.item-qty').value = parseInt(dupRow.querySelector('.item-qty').value||1) + qty; }
    row.remove(); recalc();
}

function recalc() {
    let subtotal = 0, totalGrams = 0, hasZeroPrice = false;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty   = parseInt(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const line  = qty * price;
        row.querySelector('.line-total').textContent = fmt(line);
        if (price === 0 && qty > 0) hasZeroPrice = true;
        const prod      = getRowProduct(row);
        const wGram     = parseInt(prod?.weight || 0);
        const lineGrams = wGram * qty;
        row.querySelector('.line-weight').textContent = fmtG(lineGrams);
        const weightWarn = row.querySelector('.weight-warn');
        if (weightWarn) weightWarn.style.display = (wGram===0 && prod) ? 'inline' : 'none';
        subtotal   += line;
        totalGrams += lineGrams;
    });

    const kg       = calcKg(totalGrams);
    const areaId   = document.getElementById('shippingAreaSelect').value;
    let pricePerKg = 0;
    if (areaId) {
        for (const opt of document.getElementById('qcShippingArea').options) {
            if (opt.value === areaId) { pricePerKg = parseFloat(opt.dataset.price||0); break; }
        }
    }
    const shippingFee = kg * pricePerKg;

    document.getElementById('displaySubtotal').textContent = fmt(subtotal);
    document.getElementById('displayWeight').textContent   = `${totalGrams.toLocaleString('id-ID')} g → ${kg} kg charged`;
    document.getElementById('displayShipping').textContent = fmt(shippingFee);
    document.getElementById('displayTotal').textContent    = fmt(subtotal + shippingFee);

    const calcBox = document.getElementById('shippingCalcBox');
    if (pricePerKg > 0) {
        calcBox.style.display = 'block';
        document.getElementById('shippingFeeDisplay').textContent = fmt(shippingFee);
        const noteEl = document.getElementById('shippingWeightNote');
        if (noteEl) noteEl.textContent = totalGrams > 0 ? `(${totalGrams.toLocaleString('id-ID')}g → ${kg} kg)` : '';
    }

    const warn = document.getElementById('zeroPriceWarning');
    if (warn) warn.style.display = hasZeroPrice ? 'block' : 'none';
}

// ── Add / remove items ───────────────────────────────────────────────
function addNewItem() {
    const div = document.createElement('div');
    div.className = 'item-row';
    div.dataset.index = itemIndex;
    div.innerHTML = `
        <div class="d-flex gap-2 align-items-start">
            <div style="flex:0 0 240px;">
                <div class="small text-muted mb-1">Product</div>
                <input type="hidden" name="items[${itemIndex}][product_id]" class="product-id-input">
                <input type="hidden" name="items[${itemIndex}][product_variant_id]" class="variant-id-input">
                <input type="hidden" name="items[${itemIndex}][unit_price]" class="item-price" value="0">
                <div class="product-search-wrap position-relative">
                    <input type="text" class="form-control form-control-sm product-search-input" placeholder="Code or name…" autocomplete="off">
                    <div class="product-dropdown"></div>
                </div>
                <div class="product-selected-badge mt-1" style="display:none;"></div>
            </div>
            <div style="flex:1;min-width:0;">
                <div class="small text-muted mb-1">Variant <span class="kbd-hint" style="font-size:.65rem;">↑↓ navigate · Enter select</span></div>
                <div class="var-tags">
                    <span class="no-variant-badge">— select product first —</span>
                </div>
            </div>
            <div style="flex:0 0 72px;">
                <div class="small text-muted mb-1">Qty</div>
                <input type="number" name="items[${itemIndex}][quantity]" class="form-control form-control-sm item-qty text-center" value="1" min="1" required>
            </div>
            <div style="flex:0 0 32px;margin-top:20px;">
                <button type="button" class="btn btn-sm btn-outline-danger remove-item w-100 px-0">×</button>
            </div>
        </div>
        <div class="small text-muted mt-1 ps-1">
            Line: <strong class="line-total">Rp 0</strong>
            &nbsp;·&nbsp; <span class="line-weight">0g</span>
            &nbsp;·&nbsp; <span class="product-code text-info font-monospace">—</span>
            <span class="weight-warn text-warning ms-1" style="display:none;">
                <i class="bi bi-exclamation-triangle-fill"></i> Weight is 0
            </span>
        </div>`;
    document.getElementById('itemsContainer').appendChild(div);
    initProductSearch(div.querySelector('.product-search-wrap'));
    div.querySelector('.product-search-input')?.focus();
    itemIndex++;
}

document.getElementById('addItemBtn').addEventListener('click', addNewItem);

// ── Form submit guard ─────────────────────────────────────────────────
document.getElementById('orderForm').addEventListener('submit', function(e) {
    let errors = [];

    // Check customer selected
    if (!document.getElementById('customerId').value) {
        errors.push('Please select a customer.');
    }

    // Check each item row
    document.querySelectorAll('.item-row').forEach((row, i) => {
        const prodId  = row.querySelector('.product-id-input')?.value;
        const varTags = row.querySelectorAll('.var-tag');
        const hasSelected = row.querySelector('.var-tag.selected');

        if (!prodId) {
            errors.push(`Item ${i+1}: No product selected.`);
            return;
        }

        // If variant tags exist but none selected — block submit
        if (varTags.length > 0 && !hasSelected) {
            // Highlight the row
            row.classList.add('active-row');
            row.style.borderColor = '#dc3545';
            const varLabel = row.querySelector('.var-tags');
            if (varLabel) varLabel.style.outline = '2px solid #dc3545';
            errors.push(`Item ${i+1} (${row.querySelector('.product-code')?.textContent || 'product'}): Please select a variant.`);
        }
    });

    if (errors.length) {
        e.preventDefault();

        // Show a fixed centered toast/banner so it's always visible
        let toast = document.getElementById('variantToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'variantToast';
            toast.style.cssText = [
                'position:fixed',
                'top:50%','left:50%',
                'transform:translate(-50%,-50%)',
                'z-index:9999',
                'background:#fff',
                'border:2px solid #dc3545',
                'border-radius:12px',
                'padding:1.25rem 1.5rem',
                'box-shadow:0 8px 32px rgba(0,0,0,.18)',
                'min-width:320px','max-width:480px',
                'text-align:center',
            ].join(';');
            document.body.appendChild(toast);
        }
        toast.innerHTML = `
            <div style="font-size:2rem;margin-bottom:.5rem;">⚠️</div>
            <div style="font-weight:700;font-size:1rem;color:#dc3545;margin-bottom:.5rem;">Please fix the following</div>
            <ul style="text-align:left;margin:0 0 1rem;padding-left:1.2rem;font-size:.9rem;color:#374151">
                ${errors.map(err => `<li>${err}</li>`).join('')}
            </ul>
            <button type="button" onclick="document.getElementById('variantToast').remove()"
                class="btn btn-danger btn-sm px-4">OK, I'll fix it</button>`;

        // Backdrop
        let bd = document.getElementById('variantToastBd');
        if (!bd) {
            bd = document.createElement('div');
            bd.id = 'variantToastBd';
            bd.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:9998;';
            bd.onclick = () => { bd.remove(); document.getElementById('variantToast')?.remove(); };
            document.body.appendChild(bd);
        }

        // Also scroll the first offending row into view
        const firstBad = document.querySelector('.item-row[style*="dc3545"]');
        if (firstBad) firstBad.scrollIntoView({behavior:'smooth', block:'center'});
    }
});
document.addEventListener('click', e => {
    if (e.target.classList.contains('remove-item')) {
        if (document.querySelectorAll('.item-row').length > 1) { e.target.closest('.item-row').remove(); recalc(); }
    }
});

// Alt+N to add item
document.addEventListener('keydown', e => {
    if (e.altKey && e.key === 'n') { e.preventDefault(); addNewItem(); }
});

// ── Init ─────────────────────────────────────────────────────────────
document.querySelectorAll('.product-search-wrap').forEach(w => initProductSearch(w));
const tripSel = document.getElementById('tripSelect');
if (tripSel.value) tripSel.dispatchEvent(new Event('change'));

/* ── CS Agent auto-select (remembers last used per browser) ── */
(function() {
    const csSelect = document.getElementById('csAgentSelect');
    if (!csSelect) return;
    const KEY = 'lastCsAgentId';
    // On load: if nothing chosen yet (no old() value), restore last used
    if (!csSelect.value) {
        const last = localStorage.getItem(KEY);
        if (last && csSelect.querySelector(`option[value="${last}"]`)) {
            csSelect.value = last;
        }
    }
    // Remember whenever it changes
    csSelect.addEventListener('change', function() {
        if (this.value) localStorage.setItem(KEY, this.value);
        else localStorage.removeItem(KEY);
    });
})();
</script>
@endpush