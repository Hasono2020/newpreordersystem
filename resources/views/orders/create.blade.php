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

    {{-- ── Customer + Trip ── --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 fw-semibold">Order Details</div>
        <div class="card-body">
            <div class="row g-3">

                {{-- Customer search with quick-add --}}
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
                    <input type="hidden" name="customer_id" id="customerId">
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
                    <div class="selected-customer-card mt-2" id="selectedCustomerCard">
                        <div>
                            <span class="fw-semibold" id="selectedCustomerName"></span>
                            <span class="badge bg-secondary ms-2" id="selectedCustomerType"></span>
                            <span class="text-muted small ms-2" id="selectedCustomerPhone"></span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearCustomer">Change</button>
                    </div>
                </div>

                {{-- Trip --}}
                <div class="col-md-6">
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

                {{-- Shipping area --}}
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Shipping Area</label>
                    <select name="shipping_area_id" id="shippingAreaSelect" class="form-select">
                        <option value="">— No shipping area —</option>
                        @foreach($shippingAreas as $area)
                            <option value="{{ $area->id }}"
                                data-price="{{ $area->price_per_kg }}"
                                {{ old('shipping_area_id') == $area->id ? 'selected' : '' }}>
                                {{ $area->name }}
                                @if($area->province) ({{ $area->province }}) @endif
                                — Rp {{ number_format($area->price_per_kg, 0, ',', '.') }}/kg
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Est. Shipping Fee</label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input type="text" id="shippingFeeDisplay" class="form-control bg-light" readonly value="0">
                    </div>
                    <div class="form-text" id="shippingKgNote">Select area to auto-calculate</div>
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
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Summary ── --}}
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
                    <label class="form-label fw-semibold">Phone / WhatsApp</label>
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
            data-weight="${p.weight_gram || 0}"
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

        priceIn.value   = price;
        codeEl.textContent = code || '—';

        varSel.innerHTML = '<option value="">No variant</option>';
        variants.forEach(v => {
            const lbl = [v.color, v.size].filter(Boolean).join(' / ') || 'Default';
            varSel.innerHTML += `<option value="${v.id}" data-price="${price + parseFloat(v.price_adjustment||0)}">${lbl} — Rp ${Math.round(price+parseFloat(v.price_adjustment||0)).toLocaleString('id-ID')}</option>`;
        });
        recalc();
    }
    if (e.target.classList.contains('variant-select')) {
        const row = e.target.closest('.item-row');
        const opt = e.target.options[e.target.selectedIndex];
        if (opt.dataset.price) row.querySelector('.item-price').value = opt.dataset.price;
        recalc();
    }
    if (e.target.classList.contains('item-qty') || e.target.classList.contains('item-price')) recalc();
    if (e.target.id === 'shippingAreaSelect') recalc();
});

function recalc() {
    let subtotal = 0, totalGrams = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty   = parseInt(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const line  = qty * price;
        row.querySelector('.line-total').textContent = fmt(line);

        // weight per product
        const prodSel = row.querySelector('.product-select');
        const prodOpt = prodSel.options[prodSel.selectedIndex];
        const wGram   = parseInt(prodOpt?.dataset?.weight || 0);
        const lineGrams = wGram * qty;
        row.querySelector('.line-weight').textContent = fmtG(lineGrams);

        subtotal    += line;
        totalGrams  += lineGrams;
    });

    const kg            = calcKg(totalGrams);
    const areaOpt       = document.getElementById('shippingAreaSelect').options[document.getElementById('shippingAreaSelect').selectedIndex];
    const pricePerKg    = parseFloat(areaOpt?.dataset?.price || 0);
    const shippingFee   = kg * pricePerKg;

    document.getElementById('displaySubtotal').textContent  = fmt(subtotal);
    document.getElementById('displayWeight').textContent    = `${totalGrams.toLocaleString('id-ID')} g → ${kg} kg charged`;
    document.getElementById('displayShipping').textContent  = fmt(shippingFee);
    document.getElementById('shippingFeeDisplay').value     = Math.round(shippingFee).toLocaleString('id-ID');
    document.getElementById('shippingKgNote').textContent   = totalGrams > 0 ? `${totalGrams.toLocaleString()}g → ${kg} kg` : 'Select area to auto-calculate';
    document.getElementById('displayTotal').textContent     = fmt(subtotal + shippingFee);
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
                            <div class="cust-meta">${c.phone || 'No phone'} · ${c.type.replace('_',' ')}</div>`;
                        div.addEventListener('click', () => selectCustomer(c));
                        resultsEl.appendChild(div);
                    });
                }
                dropdown.style.display = 'block';
            });
    }, 250);
});

function selectCustomer(c) {
    document.getElementById('customerId').value          = c.id;
    document.getElementById('selectedCustomerName').textContent  = c.name;
    document.getElementById('selectedCustomerPhone').textContent = c.phone || '';
    document.getElementById('selectedCustomerType').textContent  = c.type.replace('_',' ');
    selectedCard.style.display     = 'flex';
    searchInput.style.display      = 'none';
    dropdown.style.display         = 'none';
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
    const name  = document.getElementById('qcName').value.trim();
    const phone = document.getElementById('qcPhone').value.trim();
    const type  = document.getElementById('qcType').value;
    const errEl = document.getElementById('qcError');
    const spin  = document.getElementById('qcSpinner');

    if (!name) { errEl.textContent='Name is required.'; errEl.style.display='block'; return; }

    spin.classList.remove('d-none');
    fetch('/api/customers/quick', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content},
        body: JSON.stringify({name, phone, type})
    })
    .then(r => r.json())
    .then(c => {
        spin.classList.add('d-none');
        quickModal.hide();
        selectCustomer(c);
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
