@extends('layouts.app')
@section('title', 'New Order')
@section('page-title', 'New Order')

@push('styles')
<style>
.item-row { background:#f9fafb; border-radius:8px; padding:1rem; margin-bottom:.75rem; border:1px solid #e5e7eb; }
/* Customer search */
#customerDropdown { position:absolute; z-index:1050; background:#fff; border:1px solid #dee2e6; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,.12); width:100%; max-height:260px; overflow-y:auto; display:none; }
#customerDropdown .cust-item { padding:.6rem 1rem; cursor:pointer; border-bottom:1px solid #f3f4f6; }
#customerDropdown .cust-item:hover { background:#f0f9ff; }
#customerDropdown .cust-item .cust-name { font-weight:600; font-size:.9rem; }
#customerDropdown .cust-item .cust-meta { font-size:.75rem; color:#6b7280; }
#customerDropdown .cust-add-btn { padding:.7rem 1rem; color:#2563eb; cursor:pointer; font-weight:600; font-size:.85rem; border-top:2px solid #e5e7eb; }
#customerDropdown .cust-add-btn:hover { background:#eff6ff; }
.selected-customer-card { background:#f0fdf4; border:1.5px solid #86efac; border-radius:8px; padding:.6rem 1rem; display:none; align-items:center; justify-content:space-between; }
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
            <div class="row g-3">

                {{-- Customer search --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
                    <input type="hidden" name="customer_id" id="customerId">
                    {{-- Hidden shipping area - set by modal --}}
                    <input type="hidden" name="shipping_area_id" id="shippingAreaSelect">
                    <div class="position-relative">
                        <input type="text" id="customerSearch" class="form-control"
                            placeholder="Type name or phone to search…" autocomplete="off">
                        <div id="customerDropdown">
                            <div id="customerResults"></div>
                            <div class="cust-add-btn" id="quickAddBtn">
                                <i class="bi bi-person-plus me-1"></i>Add new customer…
                            </div>
                        </div>
                    </div>
                    <div id="selectedCustomerCard" class="selected-customer-card mt-2">
                        <div>
                            <span class="fw-semibold" id="selectedCustomerName"></span>
                            <span class="badge bg-secondary ms-2" id="selectedCustomerType"></span>
                            <span class="text-muted small ms-2" id="selectedCustomerPhone"></span>
                            <div class="text-muted small mt-1" id="selectedCustomerAddr" style="font-size:.75rem;"></div>
                            <div class="text-muted small mt-1" id="selectedCustomerArea" style="font-size:.75rem;"></div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearCustomer">Change</button>
                    </div>
                    {{-- Shipping calc display --}}
                    <div class="mt-2 p-2 bg-light rounded small" id="shippingCalcBox" style="display:none;">
                        <i class="bi bi-truck me-1 text-primary"></i>
                        Shipping area: <strong id="shippingAreaName"></strong>
                        &nbsp;·&nbsp; Est. fee: <strong id="shippingFeeDisplay">Rp 0</strong>
                        <span class="text-muted ms-2" id="shippingWeightNote"></span>
                    </div>
                </div>

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
            <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                <i class="bi bi-plus-lg me-1"></i>Add Item
            </button>
        </div>
        <div class="card-body" id="itemsContainer">
            <div class="item-row" data-index="0">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Product</label>
                        <select name="items[0][product_id]" class="form-select form-select-sm product-select" required>
                            <option value="">Select product…</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Variant</label>
                        <select name="items[0][product_variant_id]" class="form-select form-select-sm variant-select">
                            <option value="">No variant</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Qty</label>
                        <input type="number" name="items[0][quantity]" class="form-control form-control-sm item-qty" value="1" min="1" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Price (Rp)</label>
                        <input type="number" name="items[0][unit_price]" class="form-control form-control-sm item-price" value="0" min="0" step="1000" required>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-item w-100">×</button>
                    </div>
                </div>
                <div class="row mt-1">
                    <div class="col small text-muted">
                        Line: <strong class="line-total">Rp 0</strong>
                        &nbsp;·&nbsp; Weight: <strong class="line-weight">0g</strong>
                        &nbsp;·&nbsp; Code: <span class="product-code text-info">—</span>
                        <span class="weight-warn text-warning ms-2" style="display:none;">
                            <i class="bi bi-exclamation-triangle-fill"></i> Product weight is 0 — shipping may be incorrect. <a href="#" onclick="return false;" class="edit-product-link text-warning">Edit product</a>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Summary ── --}}
    <div id="zeroPriceWarning" class="alert alert-warning py-2 small mb-2" style="display:none;">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>Some items have a price of Rp 0.</strong> Please check the product's selling price or enter the price manually in the item row.
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

{{-- ── Quick Add Customer Modal ── --}}
<div class="modal fade" id="quickCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="qcName" class="form-control" placeholder="Customer name">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Phone / WhatsApp <span class="text-danger">*</span></label>
                    <input type="text" id="qcPhone" class="form-control" placeholder="e.g. 08123456789">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Type</label>
                    <select id="qcType" class="form-select">
                        <option value="customer">Customer</option>
                        <option value="reseller">Reseller</option>
                        <option value="selected_customer">Selected Customer</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Address</label>
                    <textarea id="qcAddress" class="form-control" rows="2" placeholder="Full shipping address…"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Shipping Area <span class="text-danger">*</span></label>
                    <select id="qcShippingArea" class="form-select">
                        <option value="">— Select shipping area —</option>
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
                <div id="qcError" class="text-danger small" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveQuickCustomer">
                    <span id="qcSpinner" class="spinner-border spinner-border-sm me-1 d-none"></span>
                    Save Customer
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// ── Helpers ──────────────────────────────────────────────────────────
let tripProducts  = [];
let itemIndex     = 1;
let searchTimeout = null;

function fmt(n)    { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }
function fmtG(g)   { return g >= 1000 ? (g/1000).toFixed(2).replace(/\.?0+$/,'') + ' kg' : g + ' g'; }
function calcKg(g) { if(g<=0) return 0; if(g<=1350) return 1; return Math.ceil((g-350)/1000); }

// ── Load trip products ───────────────────────────────────────────────
document.getElementById('tripSelect').addEventListener('change', function() {
    const id = this.value;
    if (!id) { tripProducts=[]; populateAllProductSelects(); return; }
    fetch(`/api/trips/${id}/products`)
        .then(r => r.json())
        .then(data => { tripProducts = data; populateAllProductSelects(); });
});

function populateAllProductSelects() {
    document.querySelectorAll('.product-select').forEach(sel => populateProductSelect(sel));
}

function populateProductSelect(sel) {
    const cur = sel.value;
    sel.innerHTML = '<option value="">Select product…</option>';
    tripProducts.forEach(p => {
        const label = p.product_code ? `[${p.product_code}] ${p.name}` : p.name;
        sel.innerHTML += `<option value="${p.id}"
            data-price="${p.price}"
            data-weight="${p.weight || 0}"
            data-code="${p.product_code || ''}"
            data-variants='${JSON.stringify(p.variants || [])}'>${label} — Rp ${parseInt(p.price).toLocaleString('id-ID')}</option>`;
    });
    if (cur) sel.value = cur;
}

// ── Item events ──────────────────────────────────────────────────────
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('product-select')) {
        const row     = e.target.closest('.item-row');
        const opt     = e.target.options[e.target.selectedIndex];
        const varSel  = row.querySelector('.variant-select');
        const priceIn = row.querySelector('.item-price');
        const codeEl  = row.querySelector('.product-code');

        if (!opt.value) return;
        const price    = parseFloat(opt.dataset.price) || 0;
        const variants = JSON.parse(opt.dataset.variants || '[]');
        const code     = opt.dataset.code || '—';

        priceIn.value      = price;
        codeEl.textContent = code || '—';

        // Update edit product link if weight is 0
        const weightWarn = row.querySelector('.weight-warn');
        const editLink   = row.querySelector('.edit-product-link');
        if (weightWarn && editLink) {
            editLink.href = `/products/${opt.value}/edit`;
            editLink.target = '_blank';
        }

        varSel.innerHTML = '<option value="">No variant</option>';
        variants.forEach(v => {
            const lbl = v.label || 'Default';
            const finalPrice = parseFloat(v.price) || price;
            varSel.innerHTML += `<option value="${v.id}" data-price="${finalPrice}">${lbl} — Rp ${Math.round(finalPrice).toLocaleString('id-ID')}</option>`;
        });

        checkDuplicate(row);
        recalc();
    }
    if (e.target.classList.contains('variant-select')) {
        const row = e.target.closest('.item-row');
        const opt = e.target.options[e.target.selectedIndex];
        if (opt.dataset.price) row.querySelector('.item-price').value = opt.dataset.price;
        checkDuplicate(row);
        recalc();
    }
    if (e.target.classList.contains('item-qty') || e.target.classList.contains('item-price')) recalc();
    if (e.target.id === 'shippingAreaSelect') recalc();
});

/**
 * Check if current row has the same product+variant as another row.
 * If yes, show warning with option to merge quantities.
 */
function checkDuplicate(row) {
    const prodSel = row.querySelector('.product-select');
    const varSel  = row.querySelector('.variant-select');
    const prodId  = prodSel?.value;
    const varId   = varSel?.value || '';

    // Clear previous warning
    let warn = row.querySelector('.duplicate-warn');
    if (warn) warn.remove();

    if (!prodId) return;

    // Find any other row with same product + variant
    let dupRow = null;
    document.querySelectorAll('.item-row').forEach(otherRow => {
        if (otherRow === row) return;
        const oProd = otherRow.querySelector('.product-select')?.value;
        const oVar  = otherRow.querySelector('.variant-select')?.value || '';
        if (oProd === prodId && oVar === varId) dupRow = otherRow;
    });

    if (!dupRow) return;

    // Show warning under the current row
    const prodName = prodSel.options[prodSel.selectedIndex]?.text?.split('—')[0]?.trim() || 'this product';
    const varName  = varSel.options[varSel.selectedIndex]?.text?.split('—')[0]?.trim() || '';
    const label    = varName && varName !== 'No variant' ? `${prodName} (${varName})` : prodName;

    const warnDiv = document.createElement('div');
    warnDiv.className = 'duplicate-warn alert alert-warning py-2 px-3 mt-2 small';
    warnDiv.innerHTML = `
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>${label}</strong> is already in another row.
        <button type="button" class="btn btn-sm btn-warning py-0 px-2 ms-2" onclick="mergeRow(this)">
            Merge quantities into one row
        </button>
    `;
    row.appendChild(warnDiv);
}

/**
 * Merge current row into the duplicate row by summing quantities, then remove this row.
 */
function mergeRow(btn) {
    const row    = btn.closest('.item-row');
    const prodId = row.querySelector('.product-select')?.value;
    const varId  = row.querySelector('.variant-select')?.value || '';
    const qty    = parseInt(row.querySelector('.item-qty')?.value) || 1;

    let dupRow = null;
    document.querySelectorAll('.item-row').forEach(otherRow => {
        if (otherRow === row) return;
        const oProd = otherRow.querySelector('.product-select')?.value;
        const oVar  = otherRow.querySelector('.variant-select')?.value || '';
        if (oProd === prodId && oVar === varId) dupRow = otherRow;
    });

    if (dupRow) {
        const dupQtyInput = dupRow.querySelector('.item-qty');
        dupQtyInput.value = parseInt(dupQtyInput.value || 1) + qty;
    }

    row.remove();
    recalc();
}

function recalc() {
    let subtotal = 0, totalGrams = 0;
    let hasZeroPrice = false;

    document.querySelectorAll('.item-row').forEach(row => {
        const qty   = parseInt(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const line  = qty * price;
        row.querySelector('.line-total').textContent = fmt(line);

        // Warn if price is 0
        const priceInput = row.querySelector('.item-price');
        if (price === 0 && qty > 0) {
            priceInput.classList.add('border-warning');
            hasZeroPrice = true;
        } else {
            priceInput.classList.remove('border-warning');
        }

        // weight per product
        const prodSel   = row.querySelector('.product-select');
        const prodOpt   = prodSel.options[prodSel.selectedIndex];
        const wGram     = parseInt(prodOpt?.dataset?.weight || 0);
        const lineGrams = wGram * qty;
        row.querySelector('.line-weight').textContent = fmtG(lineGrams);
        // Warn if weight is 0
        const weightWarn = row.querySelector('.weight-warn');
        if (wGram === 0 && prodOpt?.value && weightWarn) {
            weightWarn.style.display = 'block';
        } else if (weightWarn) {
            weightWarn.style.display = 'none';
        }

        subtotal   += line;
        totalGrams += lineGrams;
    });

    const kg         = calcKg(totalGrams);
    const hiddenArea = document.getElementById('shippingAreaSelect');
    const areaId     = hiddenArea ? hiddenArea.value : '';
    // Look up price_per_kg from the modal's select options
    const modalAreaSel = document.getElementById('qcShippingArea');
    let pricePerKg = 0;
    if (modalAreaSel && areaId) {
        for (const opt of modalAreaSel.options) {
            if (opt.value === areaId) { pricePerKg = parseFloat(opt.dataset.price || 0); break; }
        }
    }
    const shippingFee = kg * pricePerKg;

    // Update summary
    document.getElementById('displaySubtotal').textContent = fmt(subtotal);
    document.getElementById('displayWeight').textContent   = `${totalGrams.toLocaleString('id-ID')} g → ${kg} kg charged`;
    document.getElementById('displayShipping').textContent = fmt(shippingFee);
    document.getElementById('displayTotal').textContent    = fmt(subtotal + shippingFee);

    // Update shipping calc box in customer card
    const calcBox = document.getElementById('shippingCalcBox');
    if (calcBox) {
        if (pricePerKg > 0) {
            calcBox.style.display = 'block';
            document.getElementById('shippingFeeDisplay').textContent = fmt(shippingFee);
            const noteEl = document.getElementById('shippingWeightNote');
            if (noteEl) noteEl.textContent = totalGrams > 0 ? `(${totalGrams.toLocaleString('id-ID')}g → ${kg} kg)` : '';
        } else {
            calcBox.style.display = 'none';
        }
        const noteEl2 = document.getElementById('shippingKgNote');
        if (noteEl2) noteEl2.textContent = pricePerKg > 0
            ? `${totalGrams.toLocaleString('id-ID')}g → ${kg} kg → ${fmt(shippingFee)}`
            : 'Select area to auto-calculate shipping fee';
    }

    // Price=0 global warning
    const warn = document.getElementById('zeroPriceWarning');
    if (warn) warn.style.display = hasZeroPrice ? 'block' : 'none';
}

// ── Add / remove items ───────────────────────────────────────────────
document.getElementById('addItemBtn').addEventListener('click', () => {
    const div = document.createElement('div');
    div.className = 'item-row';
    div.dataset.index = itemIndex;
    div.innerHTML = `
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Product</label>
                <select name="items[${itemIndex}][product_id]" class="form-select form-select-sm product-select" required>
                    <option value="">Select product…</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Variant</label>
                <select name="items[${itemIndex}][product_variant_id]" class="form-select form-select-sm variant-select">
                    <option value="">No variant</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Qty</label>
                <input type="number" name="items[${itemIndex}][quantity]" class="form-control form-control-sm item-qty" value="1" min="1" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Price (Rp)</label>
                <input type="number" name="items[${itemIndex}][unit_price]" class="form-control form-control-sm item-price" value="0" min="0" step="1000" required>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-danger remove-item w-100">×</button>
            </div>
        </div>
        <div class="row mt-1">
            <div class="col small text-muted">
                Line: <strong class="line-total">Rp 0</strong>
                &nbsp;·&nbsp; Weight: <strong class="line-weight">0g</strong>
                &nbsp;·&nbsp; Code: <span class="product-code text-info">—</span>
                <span class="weight-warn text-warning ms-2" style="display:none;">
                    <i class="bi bi-exclamation-triangle-fill"></i> Product weight is 0 — shipping may be incorrect.
                </span>
            </div>
        </div>`;
    document.getElementById('itemsContainer').appendChild(div);
    populateProductSelect(div.querySelector('.product-select'));
    itemIndex++;
});

document.addEventListener('click', e => {
    if (e.target.classList.contains('remove-item')) {
        if (document.querySelectorAll('.item-row').length > 1) {
            e.target.closest('.item-row').remove();
            recalc();
        }
    }
});

// ── Customer search ──────────────────────────────────────────────────
const searchInput  = document.getElementById('customerSearch');
const dropdown     = document.getElementById('customerDropdown');
const resultsEl    = document.getElementById('customerResults');
const selectedCard = document.getElementById('selectedCustomerCard');

searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const q = this.value.trim();
    if (q.length < 1) { dropdown.style.display='none'; return; }
    searchTimeout = setTimeout(() => {
        fetch(`/api/customers/search?q=${encodeURIComponent(q)}`)
            .then(r => r.json())
            .then(data => {
                resultsEl.innerHTML = '';
                if (data.length === 0) {
                    resultsEl.innerHTML = '<div class="cust-item text-muted small">No results found</div>';
                } else {
                    data.forEach(c => {
                        const div = document.createElement('div');
                        div.className = 'cust-item';
                        div.innerHTML = `<div class="cust-name">${c.name}</div>
                            <div class="cust-meta">${c.phone || 'No phone'} · ${c.type.replace('_',' ')}${c.address ? ' · 📍 ' + c.address : ''}</div>`;
                        div.addEventListener('click', () => selectCustomer(c));
                        resultsEl.appendChild(div);
                    });
                }
                dropdown.style.display = 'block';
            });
    }, 250);
});

function selectCustomer(c) {
    document.getElementById('customerId').value = c.id;
    document.getElementById('selectedCustomerName').textContent  = c.name;
    document.getElementById('selectedCustomerPhone').textContent = c.phone || '';
    document.getElementById('selectedCustomerType').textContent  = c.type.replace('_',' ');
    const addrEl = document.getElementById('selectedCustomerAddr');
    if (addrEl) addrEl.textContent = c.address ? '📍 ' + c.address : '';

    // Auto-apply default shipping area if customer has one
    if (c.default_shipping_area_id) {
        applyShippingArea(c.default_shipping_area_id);
    }

    selectedCard.style.display = 'flex';
    searchInput.style.display  = 'none';
    dropdown.style.display     = 'none';
}

function applyShippingArea(areaId) {
    const hiddenArea  = document.getElementById('shippingAreaSelect');
    const modalSel    = document.getElementById('qcShippingArea');
    if (hiddenArea) hiddenArea.value = areaId;

    // Find the area name from the modal options
    let areaName = '', pricePerKg = 0;
    if (modalSel) {
        for (const opt of modalSel.options) {
            if (opt.value == areaId) {
                areaName   = opt.dataset.name || opt.text.split('—')[0].trim();
                pricePerKg = parseFloat(opt.dataset.price || 0);
                break;
            }
        }
    }

    const areaEl = document.getElementById('selectedCustomerArea');
    if (areaEl && areaName) areaEl.textContent = '🚚 ' + areaName;

    const calcBox    = document.getElementById('shippingCalcBox');
    const areaNameEl = document.getElementById('shippingAreaName');
    if (calcBox)    calcBox.style.display = pricePerKg > 0 ? 'block' : 'none';
    if (areaNameEl) areaNameEl.textContent = areaName;

    recalc();
}

document.getElementById('clearCustomer').addEventListener('click', () => {
    document.getElementById('customerId').value = '';
    searchInput.value = '';
    searchInput.style.display = '';
    selectedCard.style.display = 'none';
    searchInput.focus();
});

document.addEventListener('click', e => {
    if (!e.target.closest('#customerSearch') && !e.target.closest('#customerDropdown')) {
        dropdown.style.display = 'none';
    }
});

// ── Quick add customer ───────────────────────────────────────────────
const quickModal = new bootstrap.Modal(document.getElementById('quickCustomerModal'));

document.getElementById('quickAddBtn').addEventListener('click', () => {
    document.getElementById('qcName').value  = searchInput.value;
    document.getElementById('qcPhone').value = '';
    document.getElementById('qcError').style.display = 'none';
    dropdown.style.display = 'none';
    quickModal.show();
});

document.getElementById('saveQuickCustomer').addEventListener('click', () => {
    const name    = document.getElementById('qcName').value.trim();
    const phone   = document.getElementById('qcPhone').value.trim();
    const type    = document.getElementById('qcType').value;
    const address = document.getElementById('qcAddress').value.trim();
    const areaSel = document.getElementById('qcShippingArea');
    const areaId  = areaSel.value;
    const areaOpt = areaSel.options[areaSel.selectedIndex];
    const errEl   = document.getElementById('qcError');
    const spin    = document.getElementById('qcSpinner');

    // Validate required fields
    if (!name)    { errEl.textContent='Name is required.'; errEl.style.display='block'; return; }
    if (!phone)   { errEl.textContent='Phone is required.'; errEl.style.display='block'; return; }
    if (!areaId)  { errEl.textContent='Shipping Area is required.'; errEl.style.display='block'; return; }
    errEl.style.display = 'none';

    spin.classList.remove('d-none');
    fetch('/api/customers/quick', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content},
        body: JSON.stringify({name, phone, type, address, default_shipping_area_id: areaId})
    })
    .then(r => r.json())
    .then(data => {
        spin.classList.add('d-none');

        // Bug 8 fix: handle duplicate phone warning
        if (data.duplicate) {
            if (confirm(`⚠️ ${data.message}\n\nDo you want to use this existing customer instead?`)) {
                quickModal.hide();
                selectCustomer(data.customer);
                if (areaId) applyShippingArea(areaId);
            } else {
                errEl.textContent = 'Please use a different phone number or search for the existing customer.';
                errEl.style.display = 'block';
            }
            return;
        }

        const c = data.customer ?? data;
        quickModal.hide();
        selectCustomer(c);
        if (areaId) applyShippingArea(areaId);
    })
    .catch(() => {
        spin.classList.add('d-none');
        errEl.textContent='Error saving customer. Try again.'; errEl.style.display='block';
    });
});

// init
const tripSel = document.getElementById('tripSelect');
if (tripSel.value) tripSel.dispatchEvent(new Event('change'));
</script>
@endpush
