@extends('layouts.app')
@section('title', 'Edit PO')
@section('page-title', 'Edit PO')

@push('styles')
<style>
.po-sticky-bar {
    position:sticky;top:0;z-index:100;background:#1e293b;color:#fff;
    padding:.5rem 1rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;
    box-shadow:0 2px 10px rgba(0,0,0,.2);margin:-.5rem -1.5rem 1.25rem -1.5rem;
}
/* Unified variant table */
#variantTable { width:100%;border-collapse:collapse; }
#variantTable thead th {
    position:sticky;top:0;background:#f8fafc;z-index:5;
    font-size:.75rem;padding:.45rem .6rem;border-bottom:2px solid #e5e7eb;
    font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.04em;
}
#variantTable tbody tr { border-bottom:1px solid #f1f5f9; }
#variantTable tbody tr:hover { background:#f8fafc; }
#variantTable td { padding:.35rem .6rem;font-size:.83rem;vertical-align:middle; }
.row-new  { background:#f0fdf4 !important; }
.row-new:hover { background:#dcfce7 !important; }
.qty-input { width:72px; }
.cost-input { width:100px; }
.save-tick { color:#16a34a;display:none; }
.product-col { max-width:220px; }
.product-name { font-weight:600; }
.product-code { font-size:.68rem;font-family:monospace;color:#6366f1; }
.variant-col { color:#475569; }
.demand-badge { font-size:.7rem;font-weight:700;color:#fff;background:#2563eb;border-radius:10px;padding:1px 7px; }
.new-badge { font-size:.68rem;background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:4px;padding:1px 5px; }
#tableWrap { max-height:65vh;overflow-y:auto; }
</style>
@endpush

@section('content')

{{-- Sticky bar --}}
<div class="po-sticky-bar">
    <a href="{{ route('purchasing.show', $purchasing) }}" class="btn btn-sm btn-outline-light py-1 px-2">
        <i class="bi bi-arrow-left"></i>
    </a>
    <span class="badge {{ match($purchasing->status){ 'arrived'=>'bg-success','confirmed'=>'bg-primary','submitted'=>'bg-warning text-dark',default=>'bg-secondary'} }}">
        {{ ucfirst($purchasing->status) }}
    </span>
    <span style="font-size:.85rem;">{{ $purchasing->po_number }}</span>
    <span class="text-secondary d-none d-md-inline" style="font-size:.8rem;">{{ $purchasing->supplier?->name ?? '—' }} · {{ $purchasing->trip->name }}</span>
    <div class="ms-auto d-flex gap-2">
        <a href="{{ route('purchasing.show', $purchasing) }}" class="btn btn-sm btn-outline-light py-1">Cancel</a>
        <button type="submit" form="editForm" class="btn btn-sm btn-success py-1 px-3">
            <i class="bi bi-check-circle me-1"></i>Save Changes
        </button>
    </div>
</div>

{{-- PO meta --}}
<form method="POST" action="{{ route('purchasing.update', $purchasing) }}" id="editForm">
@csrf @method('PUT')
<div class="card mb-3">
    <div class="card-header bg-white py-3 fw-semibold">PO Details</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Supplier</label>
                <select name="supplier_id" class="form-select">
                    <option value="">— No Supplier —</option>
                    @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ $purchasing->supplier_id == $s->id ? 'selected' : '' }}>
                            {{ $s->name }}{{ $s->country ? ' ('.$s->country.')' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    @foreach(['draft'=>'Draft','submitted'=>'Submitted','confirmed'=>'Confirmed'] as $val=>$lbl)
                        <option value="{{ $val }}" {{ $purchasing->status==$val?'selected':'' }}>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Purchase Date</label>
                <input type="date" name="purchased_at" class="form-control"
                    value="{{ $purchasing->purchased_at?->format('Y-m-d') }}">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Notes</label>
                <textarea name="notes" class="form-control" rows="2">{{ $purchasing->notes }}</textarea>
            </div>
        </div>
    </div>
</div>
</form>

{{-- Unified variant list --}}
@php
// Build combined list: existing items + new demand
$existingMap = $purchasing->items->keyBy(fn($i) => $i->product_id.'_'.($i->product_variant_id ?? 'null'));

// Pass existing items as JSON for AJAX saves
$existingJson = $purchasing->items->map(fn($i) => [
    'id'      => $i->id,
    'key'     => $i->product_id.'_'.($i->product_variant_id ?? 'null'),
    'qty'     => $i->quantity_ordered,
    'cost'    => (float) $i->unit_cost,
])->keyBy('key')->values()->all();

// Build total demand map for this supplier+trip
$totalDemandMap = DB::table('order_items')
    ->join('orders', 'orders.id', '=', 'order_items.order_id')
    ->join('products', 'products.id', '=', 'order_items.product_id')
    ->where('orders.trip_id', $purchasing->trip_id)
    ->where('products.supplier_id', $purchasing->supplier_id)
    ->whereIn('order_items.status', ['pending', 'confirmed'])
    ->selectRaw('CONCAT(products.id, "_", COALESCE(order_items.product_variant_id, "null")) as dkey,
                 SUM(order_items.quantity) as total_qty')
    ->groupBy('dkey')
    ->pluck('total_qty', 'dkey')
    ->toArray();

// Combine: existing items first, then new demand
$combinedRows = collect();

// Existing items
foreach($purchasing->items as $item) {
    // Get total demand for this variant
    $demandKey  = $item->product_id.'_'.($item->product_variant_id ?? 'null');
    $totalDem   = isset($totalDemandMap[$demandKey]) ? (int)$totalDemandMap[$demandKey] : null;
    $suggestQty = $totalDem ?? $item->quantity_ordered; // suggest total demand as qty
    $combinedRows->push([
        'type'         => 'existing',
        'item_id'      => $item->id,
        'product_id'   => $item->product_id,
        'variant_id'   => $item->product_variant_id,
        'product'      => $item->product->name,
        'code'         => $item->product->product_code ?? '',
        'variant'      => $item->variant?->label ?? 'Default',
        'qty'          => $item->quantity_ordered,
        'cost'         => (float) $item->unit_cost,
        'demand'       => null,
        'total_demand' => $totalDem,
        'suggest_qty'  => $suggestQty,
    ]);
}

// New demand rows (not in existing)
foreach($newDemand as $nd) {
    $key = $nd->product_id.'_'.($nd->variant_id ?? 'null');
    if (!$existingMap->has($key)) {
        $combinedRows->push([
            'type'        => 'new',
            'item_id'     => null,
            'product_id'  => $nd->product_id,
            'variant_id'  => $nd->variant_id,
            'product'     => $nd->product_name,
            'code'        => $nd->product_code ?? '',
            'variant'     => $nd->variant_label,
            'qty'         => $nd->total_demanded, // pre-fill with full demand
            'cost'        => 0,
            'demand'      => $nd->total_demanded,
            'total_demand'=> $nd->total_demanded,
            'suggest_qty' => $nd->total_demanded,
        ]);
    }
}
@endphp

<div class="card">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <span class="fw-semibold">All Variants</span>
            <span class="badge bg-secondary ms-1">{{ $purchasing->items->count() }} existing</span>
            @if($newDemand->count() > 0)
            <span class="badge bg-success ms-1">{{ $newDemand->count() }} new demand</span>
            @endif
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="small text-muted d-none d-md-inline"><span class="new-badge">NEW</span> = new demand (pre-filled) · <span style="color:#f59e0b;font-weight:600;">⚠</span> = qty updated to match total demand · auto-saves on change</span>
            <input type="text" id="searchInput" class="form-control form-control-sm" style="width:180px;"
                placeholder="Search…" oninput="filterTable(this.value)">
        </div>
    </div>
    <div id="tableWrap">
        <table id="variantTable">
            <thead>
                <tr>
                    <th style="width:30%">Product</th>
                    <th style="width:18%">Variant</th>
                    <th style="width:8%" class="text-center">Demand</th>
                    <th style="width:12%">Qty in PO</th>
                    <th style="width:14%">Unit Cost</th>
                    <th style="width:10%" class="text-end">Line Total</th>
                    <th style="width:8%"></th>
                </tr>
            </thead>
            <tbody id="tableBody">
                {{-- Rendered by JS --}}
            </tbody>
        </table>
    </div>
</div>

@php
$rowsJson = $combinedRows->values()->all();
@endphp
<script>
const ROWS       = {!! json_encode($rowsJson) !!};
const PO_ID      = {{ $purchasing->id }};
const CSRF       = document.querySelector('meta[name=csrf-token]').content;
const PAGE       = 100;
let   filtered   = [...ROWS];
let   rendered   = 0;
let   saveTimers = {};

function escH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtRp(n) { return 'Rp '+Math.round(n||0).toLocaleString('id-ID'); }

function renderChunk() {
    const body  = document.getElementById('tableBody');
    const chunk = filtered.slice(rendered, rendered + PAGE);
    if (!chunk.length) return;

    body.insertAdjacentHTML('beforeend', chunk.map((r, ci) => {
        const isNew     = r.type === 'new';
        const totalDem  = r.total_demand || null;
        const poQty     = r.qty || 0;
        const shortfall = totalDem !== null ? totalDem - poQty : 0;
        // Use suggest_qty as the input value (total demand for existing, demand for new)
        const inputQty  = r.suggest_qty ?? poQty;
        const rowCls    = isNew ? 'row-new' : (shortfall > 0 ? 'table-warning' : '');

        let demBadge;
        if (isNew) {
            demBadge = `<span class="demand-badge">${r.demand}</span>`;
        } else if (totalDem !== null) {
            demBadge = shortfall > 0
                ? `<span class="demand-badge" style="background:#f59e0b;" title="${shortfall} still uncovered — input pre-filled with total demand ${totalDem}">${totalDem} ⚠</span>`
                : `<span style="font-size:.75rem;color:#16a34a;">✓${totalDem}</span>`;
        } else {
            demBadge = `<span class="text-muted small">—</span>`;
        }
        const newBadge  = isNew ? `<span class="new-badge ms-1">NEW</span>` : '';
        const lineTotal = (inputQty||0) * (r.cost||0);
        const rowId     = isNew ? `new_${rendered+ci}` : `ex_${r.item_id}`;

        return `<tr class="${rowCls}" id="tr-${rowId}"
                    data-search="${escH((r.product+' '+(r.code||'')+' '+r.variant).toLowerCase())}"
                    data-type="${r.type}"
                    data-item-id="${r.item_id||''}"
                    data-product-id="${r.product_id}"
                    data-variant-id="${r.variant_id||''}">
            <td class="product-col">
                <span class="product-name">${escH(r.product)}</span>
                ${r.code ? `<span class="product-code ms-1">${escH(r.code)}</span>` : ''}
                ${newBadge}
            </td>
            <td class="variant-col">${escH(r.variant)}</td>
            <td class="text-center">${demBadge}</td>
            <td>
                <input type="number" class="form-control form-control-sm qty-input"
                    value="${inputQty||0}" min="0"
                    data-row="${rowId}" data-cost="${r.cost||0}"
                    oninput="onQtyChange('${rowId}', this)">
            </td>
            <td>
                <input type="number" class="form-control form-control-sm cost-input"
                    value="${r.cost||0}" min="0" step="1"
                    data-row="${rowId}"
                    oninput="onCostChange('${rowId}', this)">
            </td>
            <td class="text-end small fw-semibold" id="lt-${rowId}">${fmtRp(lineTotal)}</td>
            <td class="text-center">
                <span class="save-tick" id="tick-${rowId}"><i class="bi bi-check-circle-fill"></i></span>
            </td>
        </tr>`;
    }).join(''));

    rendered += chunk.length;
}

// ── Event handlers ────────────────────────────────────────────────────
function onQtyChange(rowId, input) {
    const row  = document.getElementById(`tr-${rowId}`);
    const cost = parseFloat(row.querySelector('.cost-input').value) || 0;
    const qty  = parseInt(input.value) || 0;
    document.getElementById(`lt-${rowId}`).textContent = fmtRp(qty * cost);
    input.dataset.cost = cost;
    scheduleSave(rowId);
    updateSaveBtnLabel();
}

function updateSaveBtnLabel() {
    const newWithQty = [...document.querySelectorAll('#tableBody tr[data-type="new"]')]
        .filter(r => parseInt(r.querySelector('.qty-input')?.value || 0) > 0).length;
    const btn = document.querySelector('[form="editForm"]');
    if (!btn) return;
    btn.innerHTML = newWithQty > 0
        ? `<i class="bi bi-check-circle me-1"></i>Save Changes <span class="badge bg-success ms-1">${newWithQty} new</span>`
        : `<i class="bi bi-check-circle me-1"></i>Save Changes`;
}

function onCostChange(rowId, input) {
    const row = document.getElementById(`tr-${rowId}`);
    const qty = parseInt(row.querySelector('.qty-input').value) || 0;
    const cost = parseFloat(input.value) || 0;
    document.getElementById(`lt-${rowId}`).textContent = fmtRp(qty * cost);
    scheduleSave(rowId);
}

function scheduleSave(rowId) {
    clearTimeout(saveTimers[rowId]);
    saveTimers[rowId] = setTimeout(() => doSave(rowId), 700);
}

async function doSave(rowId) {
    const row     = document.getElementById(`tr-${rowId}`);
    if (!row) return;
    const qty     = parseInt(row.querySelector('.qty-input').value) || 0;
    const cost    = parseFloat(row.querySelector('.cost-input').value) || 0;
    const type    = row.dataset.type;
    const itemId  = row.dataset.itemId;
    const prodId  = row.dataset.productId;
    const varId   = row.dataset.variantId || '';
    const tick    = document.getElementById(`tick-${rowId}`);

    try {
        if (type === 'existing' && itemId) {
            // PATCH existing item
            const res = await fetch(`/purchasing/${PO_ID}/item/${itemId}`, {
                method: 'PATCH',
                headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},
                body: new URLSearchParams({quantity_ordered: qty, unit_cost: cost})
            });
            const d = await res.json();
            if (d.ok) flashTick(tick, row);
        } else if (type === 'new' && qty > 0) {
            // POST new item — only send variant_id if it has a value
            const params = new URLSearchParams({product_id: prodId, quantity_ordered: qty, unit_cost: cost});
            if (varId) params.append('product_variant_id', varId);
            const res = await fetch(`/purchasing/${PO_ID}/add-item`, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},
                body: params
            });
            const d = await res.json();
            if (d.ok) {
                // Mark row as existing so it won't re-appear as new demand
                row.dataset.type   = 'existing';
                row.dataset.itemId = d.item_id;
                row.classList.remove('row-new');
                row.id = `tr-ex_${d.item_id}`;
                // Update ROWS array to prevent re-render as NEW after filter/scroll
                const ri = ROWS.findIndex(r =>
                    r.type === 'new' &&
                    String(r.product_id) === String(prodId) &&
                    (String(r.variant_id||'') === String(varId))
                );
                if (ri !== -1) { ROWS[ri].type = 'existing'; ROWS[ri].item_id = d.item_id; }
                flashTick(tick, row);
            }
        }
    } catch(e) { console.error('Save error', e); }
}

function flashTick(tick, row) {
    if (!tick) return;
    tick.style.display = '';
    row.style.background = '#f0fdf4';
    setTimeout(() => { tick.style.display='none'; row.style.background=''; }, 2000);
}

// ── Search ────────────────────────────────────────────────────────────
function filterTable(q) {
    q = q.trim().toLowerCase();
    filtered = q ? ROWS.filter(r => (r.product+' '+(r.code||'')+' '+r.variant).toLowerCase().includes(q)) : [...ROWS];
    document.getElementById('tableBody').innerHTML = '';
    rendered = 0;
    while (rendered < filtered.length) renderChunk();
}

// ── Scroll load ───────────────────────────────────────────────────────
document.getElementById('tableWrap').addEventListener('scroll', function() {
    if (rendered >= filtered.length) return;
    if (this.scrollTop + this.clientHeight >= this.scrollHeight - 100) renderChunk();
});

// ── Main form submit — save any unsaved NEW rows first, then submit header ──
document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    // Find all NEW rows with qty > 0 that haven't been saved yet
    const unsaved = [...document.querySelectorAll('#tableBody tr[data-type="new"]')]
        .filter(row => parseInt(row.querySelector('.qty-input')?.value || 0) > 0);

    if (unsaved.length > 0) {
        const saveBtn = document.querySelector('[form="editForm"]');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…'; }

        // Cancel any pending debounce timers and save immediately
        for (const row of unsaved) {
            const rowId = row.id.replace('tr-', '');
            clearTimeout(saveTimers[rowId]);
            await doSave(rowId);
        }
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Changes'; }
    }

    // Now submit the form for header fields
    this.submit();
});

// Init
renderChunk();
</script>

@endsection