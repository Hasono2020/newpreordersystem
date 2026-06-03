@extends('layouts.app')
@section('title', 'New Order')
@section('page-title', 'New Order')

@push('styles')
<style>
.item-row { background: #f9fafb; border-radius: 8px; padding: 1rem; margin-bottom: .75rem; border: 1px solid #e5e7eb; }
</style>
@endpush

@section('content')
<div class="row justify-content-center">
<div class="col-lg-9">
<form method="POST" action="{{ route('orders.store') }}" id="orderForm">
    @csrf

    <div class="card mb-3">
        <div class="card-header bg-white py-3 fw-semibold">Order Details</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Trip <span class="text-danger">*</span></label>
                    <select name="trip_id" id="tripSelect" class="form-select @error('trip_id') is-invalid @enderror" required>
                        <option value="">Select trip…</option>
                        @foreach($trips as $trip)
                            <option value="{{ $trip->id }}" {{ old('trip_id', $selectedTrip?->id) == $trip->id ? 'selected' : '' }}>
                                {{ $trip->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('trip_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
                    <select name="customer_id" class="form-select @error('customer_id') is-invalid @enderror" required>
                        <option value="">Select customer…</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" data-type="{{ $customer->type }}"
                                {{ old('customer_id') == $customer->id ? 'selected' : '' }}>
                                {{ $customer->name }} ({{ $customer->type_label }})
                            </option>
                        @endforeach
                    </select>
                    @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Shipping Fee (Rp)</label>
                    <input type="number" name="shipping_fee" id="shippingFee" class="form-control" value="{{ old('shipping_fee', 0) }}" step="1000" min="0">
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Notes</label>
                    <input type="text" name="notes" class="form-control" value="{{ old('notes') }}" placeholder="Any special instructions…">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Order Items</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn"><i class="bi bi-plus-lg me-1"></i>Add Item</button>
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
                        <label class="form-label small fw-semibold">Unit Price (Rp)</label>
                        <input type="number" name="items[0][unit_price]" class="form-control form-control-sm item-price" value="0" min="0" step="1000" required>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-item">×</button>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col">
                        <small class="text-muted">Line total: <strong class="line-total">Rp 0</strong></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row justify-content-end">
                <div class="col-md-5">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted">Subtotal</td><td class="text-end fw-semibold" id="displaySubtotal">Rp 0</td></tr>
                        <tr><td class="text-muted">Shipping Fee</td><td class="text-end" id="displayShipping">Rp 0</td></tr>
                        <tr class="text-success"><td>Promo / Discount</td><td class="text-end" id="displayDiscount">—</td></tr>
                        <tr class="fw-bold border-top"><td>Estimated Total</td><td class="text-end fs-5" id="displayTotal">Rp 0</td></tr>
                    </table>
                    <div class="mt-2 text-muted small" id="promoNote"></div>
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
let itemIndex = 1;
let tripProducts = [];

// Format IDR
function fmt(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }

// Load products when trip changes
document.getElementById('tripSelect').addEventListener('change', function() {
    const tripId = this.value;
    if (!tripId) { tripProducts = []; populateProductSelects(); return; }
    fetch(`/api/trips/${tripId}/products`)
        .then(r => r.json())
        .then(products => { tripProducts = products; populateProductSelects(); });
});

function populateProductSelects() {
    document.querySelectorAll('.product-select').forEach(sel => {
        const val = sel.value;
        sel.innerHTML = '<option value="">Select product…</option>';
        tripProducts.forEach(p => {
            sel.innerHTML += `<option value="${p.id}" data-price="${p.price}" data-variants='${JSON.stringify(p.variants)}'>${p.name} — Rp ${parseInt(p.price).toLocaleString('id-ID')}</option>`;
        });
        if (val) sel.value = val;
    });
}

// When product changes, populate variants and price
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('product-select')) {
        const row = e.target.closest('.item-row');
        const variantSel = row.querySelector('.variant-select');
        const priceInput = row.querySelector('.item-price');
        const opt = e.target.options[e.target.selectedIndex];
        if (!opt.value) return;

        const price = parseFloat(opt.dataset.price) || 0;
        const variants = JSON.parse(opt.dataset.variants || '[]');

        priceInput.value = price;
        variantSel.innerHTML = '<option value="">No variant</option>';
        variants.forEach(v => {
            const label = [v.color, v.size].filter(Boolean).join(' / ') || 'Default';
            const adjPrice = price + parseFloat(v.price_adjustment);
            variantSel.innerHTML += `<option value="${v.id}" data-price="${adjPrice}">${label} — Rp ${Math.round(adjPrice).toLocaleString('id-ID')}</option>`;
        });
        recalc();
    }
    if (e.target.classList.contains('variant-select')) {
        const row = e.target.closest('.item-row');
        const opt = e.target.options[e.target.selectedIndex];
        if (opt.dataset.price) {
            row.querySelector('.item-price').value = opt.dataset.price;
        }
        recalc();
    }
    if (e.target.classList.contains('item-qty') || e.target.classList.contains('item-price')) recalc();
    if (e.target.id === 'shippingFee') recalc();
});

function recalc() {
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const line = qty * price;
        row.querySelector('.line-total').textContent = fmt(line);
        subtotal += line;
    });
    const shipping = parseFloat(document.getElementById('shippingFee').value) || 0;
    document.getElementById('displaySubtotal').textContent = fmt(subtotal);
    document.getElementById('displayShipping').textContent = fmt(shipping);
    document.getElementById('displayTotal').textContent = fmt(subtotal + shipping);
    document.getElementById('displayDiscount').textContent = '(calculated on save)';
    document.getElementById('promoNote').textContent = 'Promo discounts are applied automatically based on item count when saved.';
}

// Add item
document.getElementById('addItemBtn').addEventListener('click', function() {
    const container = document.getElementById('itemsContainer');
    const div = document.createElement('div');
    div.className = 'item-row';
    div.dataset.index = itemIndex;
    div.innerHTML = `
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Product</label>
                <select name="items[${itemIndex}][product_id]" class="form-select form-select-sm product-select" required>
                    <option value="">Select product…</option>
                    ${tripProducts.map(p => `<option value="${p.id}" data-price="${p.price}" data-variants='${JSON.stringify(p.variants)}'>${p.name} — Rp ${parseInt(p.price).toLocaleString('id-ID')}</option>`).join('')}
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
                <label class="form-label small fw-semibold">Unit Price (Rp)</label>
                <input type="number" name="items[${itemIndex}][unit_price]" class="form-control form-control-sm item-price" value="0" min="0" step="1000" required>
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-sm btn-outline-danger remove-item">×</button>
            </div>
        </div>
        <div class="row mt-2"><div class="col"><small class="text-muted">Line total: <strong class="line-total">Rp 0</strong></small></div></div>
    `;
    container.appendChild(div);
    itemIndex++;
});

// Remove item
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-item')) {
        const rows = document.querySelectorAll('.item-row');
        if (rows.length > 1) { e.target.closest('.item-row').remove(); recalc(); }
    }
});

// Init if trip already selected
const tripSel = document.getElementById('tripSelect');
if (tripSel.value) tripSel.dispatchEvent(new Event('change'));
</script>
@endpush
