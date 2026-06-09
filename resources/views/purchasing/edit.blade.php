@extends('layouts.app')
@section('title', 'Edit PO')
@section('page-title', 'Edit Purchase Order')

@push('styles')
<style>
.new-demand-row { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:.5rem .75rem; margin-bottom:4px; display:flex; align-items:center; gap:.75rem; font-size:.83rem; }
.new-demand-row .nd-name { flex:1; font-weight:600; }
.new-demand-row .nd-variant { width:140px; color:#475569; }
.new-demand-row .nd-demand { width:60px; text-align:center; }
.new-demand-row input[type=number] { width:85px; }
.new-demand-row .nd-check { width:28px; text-align:center; }
.po-sticky-bar {
    position: sticky; top: 0; z-index: 100;
    background: #1e293b; color: #fff;
    padding: .55rem 1rem;
    display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
    box-shadow: 0 2px 10px rgba(0,0,0,.18);
    margin: -.5rem -1.5rem 1.25rem -1.5rem;
}
</style>
@endpush

@section('content')

{{-- Sticky top bar --}}
<div class="po-sticky-bar">
    <a href="{{ route('purchasing.show', $purchasing) }}" class="btn btn-sm btn-outline-light py-1 px-2">
        <i class="bi bi-arrow-left"></i>
    </a>
    <span class="badge {{ match($purchasing->status){ 'arrived'=>'bg-success','confirmed'=>'bg-primary','submitted'=>'bg-warning text-dark',default=>'bg-secondary'} }}">
        {{ ucfirst($purchasing->status) }}
    </span>
    <span style="font-size:.85rem;">{{ $purchasing->po_number }}</span>
    <span class="text-secondary" style="font-size:.8rem;">{{ $purchasing->supplier?->name ?? '—' }} · {{ $purchasing->trip->name }}</span>
    <div class="ms-auto d-flex gap-2">
        <a href="{{ route('purchasing.show', $purchasing) }}" class="btn btn-sm btn-outline-light py-1">Cancel</a>
        <button type="submit" form="editForm" class="btn btn-sm btn-success py-1 px-3">
            <i class="bi bi-check-circle me-1"></i>Save Changes
        </button>
    </div>
</div>

<form method="POST" action="{{ route('purchasing.update', $purchasing) }}" id="editForm">
@csrf @method('PUT')

{{-- PO meta --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 fw-semibold">PO Details — {{ $purchasing->po_number }}</div>
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
                    @foreach(['draft'=>'Draft','submitted'=>'Submitted','confirmed'=>'Confirmed'] as $val => $lbl)
                        <option value="{{ $val }}" {{ $purchasing->status == $val ? 'selected' : '' }}>{{ $lbl }}</option>
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

@php
$newDemandJson = $newDemand->map(fn($r) => [
    'product_id'     => $r->product_id,
    'product_name'   => $r->product_name,
    'product_code'   => $r->product_code ?? '',
    'variant_id'     => $r->variant_id,
    'variant_label'  => $r->variant_label,
    'total_demanded' => (int) $r->total_demanded,
])->values()->all();
@endphp

{{-- ── NEW DEMAND panel ── --}}
@if($newDemand->count() > 0)
<div class="card mb-3 border-success">
    <div class="card-header bg-success bg-opacity-10 py-3 d-flex justify-content-between align-items-center">
        <div>
            <span class="fw-semibold text-success"><i class="bi bi-plus-circle me-2"></i>New Demand to Add</span>
            <span class="badge bg-success ms-2">{{ $newDemand->count() }} variants</span>
            <span class="badge bg-light text-dark border ms-1">{{ $newDemand->sum('total_demanded') }} pcs</span>
        </div>
        <div class="d-flex gap-2">
            <input type="text" class="form-control form-control-sm" style="width:200px;"
                placeholder="Search…" oninput="filterNewDemand(this.value)">
            <button type="button" class="btn btn-sm btn-outline-success" onclick="selectAllNew(true)">
                <i class="bi bi-check-all me-1"></i>Select All
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllNew(false)">
                Deselect All
            </button>
        </div>
    </div>
    <div class="card-body py-2">
        <div class="alert alert-info py-2 small mb-2">
            <i class="bi bi-info-circle me-1"></i>
            Orders imported <strong>after</strong> this PO was created. Tick items to add, set unit cost, click <strong>Save Changes</strong>.
        </div>
        {{-- Virtual scroll container --}}
        <div id="ndScroll" style="max-height:400px;overflow-y:auto;">
            <div id="ndList"></div>
        </div>
        {{-- Hidden inputs container — only populated for checked items --}}
        <div id="ndHiddens"></div>
    </div>
</div>
@else
<div class="alert alert-success py-2 small mb-3">
    <i class="bi bi-check-circle me-1"></i>No new demand — all pending orders for this supplier are already in this PO.
</div>
@endif

{{-- ── Existing PO items (saved inline via AJAX, NOT submitted with main form) ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <div>
            <span class="fw-semibold">Existing Items</span>
            <span class="badge bg-secondary ms-1">{{ $purchasing->items->count() }} lines</span>
            <span class="badge bg-light text-dark border ms-1">{{ number_format($purchasing->items->sum('quantity_ordered')) }} pcs</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted"><i class="bi bi-info-circle me-1"></i>Changes save automatically per row</span>
            <input type="text" class="form-control form-control-sm" style="width:180px;"
                placeholder="Search…" oninput="filterExisting(this.value)">
        </div>
    </div>
    <div class="card-body py-2" id="existingItems">
        @foreach($purchasing->items as $item)
        <div class="row g-2 align-items-center mb-2 p-2 border rounded existing-row"
             data-name="{{ strtolower($item->product->name . ' ' . ($item->product->product_code ?? '') . ' ' . ($item->variant?->label ?? '')) }}"
             id="erow-{{ $item->id }}">
            <div class="col-md-4 small">
                <strong>{{ $item->product->name }}</strong>
                @if($item->product->product_code)
                    <span class="badge bg-light text-primary border font-monospace ms-1" style="font-size:.68rem;">{{ $item->product->product_code }}</span>
                @endif
                @if($item->variant)<br><span class="text-muted">{{ $item->variant->label }}</span>@endif
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Qty</label>
                <input type="number" class="form-control form-control-sm ei-qty"
                    data-id="{{ $item->id }}"
                    value="{{ $item->quantity_ordered }}" min="0"
                    onchange="saveItem({{ $item->id }})">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Unit Cost (Rp)</label>
                <input type="number" class="form-control form-control-sm ei-cost"
                    data-id="{{ $item->id }}"
                    value="{{ $item->unit_cost }}" step="1000" min="0"
                    onchange="saveItem({{ $item->id }})">
            </div>
            <div class="col-md-2 small" id="erow-line-{{ $item->id }}">
                Line: <strong>Rp {{ number_format($item->line_total, 0, ',', '.') }}</strong>
            </div>
            <div class="col-md-1" id="erow-status-{{ $item->id }}"></div>
        </div>
        @endforeach
    </div>
</div>

<div class="d-flex gap-2 pb-4">
    <button type="submit" class="btn btn-primary px-4">
        <i class="bi bi-check-lg me-1"></i>Save Changes
    </button>
    <a href="{{ route('purchasing.show', $purchasing) }}" class="btn btn-outline-secondary">Cancel</a>
</div>

</form>
@endsection

@push('scripts')
<script>
// ── New demand virtual list ───────────────────────────────────────────
const ND_ALL     = {!! json_encode($newDemandJson ?? []) !!};
let   ndFiltered = [...ND_ALL];
let   ndChecked  = {}; // index → {qty, cost}
const PAGE       = 80;
let   ndRendered = 0;

function escH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function renderNDChunk() {
    const list  = document.getElementById('ndList');
    const chunk = ndFiltered.slice(ndRendered, ndRendered + PAGE);
    if (!chunk.length) return;
    list.insertAdjacentHTML('beforeend', chunk.map((r, ci) => {
        const i      = ndRendered + ci; // original index in ndFiltered
        const origIdx = ND_ALL.indexOf(r);
        const chk    = ndChecked[origIdx];
        const bg     = chk ? 'background:#f0fdf4;' : '';
        return `<div class="new-demand-row" id="ndrow-${origIdx}" style="${bg}" data-orig="${origIdx}"
                     data-search="${escH((r.product_name+' '+(r.product_code||'')+' '+r.variant_label).toLowerCase())}">
            <div class="nd-check">
                <input type="checkbox" class="form-check-input nd-check-input" data-orig="${origIdx}"
                    ${chk ? 'checked' : ''}
                    onchange="toggleND(this, ${origIdx})">
            </div>
            <div class="nd-name">${escH(r.product_name)}
                ${r.product_code ? `<span class="badge bg-light text-primary border font-monospace ms-1" style="font-size:.68rem;">${escH(r.product_code)}</span>` : ''}
            </div>
            <div class="nd-variant text-muted">${escH(r.variant_label)}</div>
            <div class="nd-demand"><span class="badge bg-primary">${r.total_demanded}</span>
                <div style="font-size:.65rem;color:#9ca3af;">demand</div></div>
            <div>
                <input type="number" class="form-control form-control-sm nd-qty-vis" data-orig="${origIdx}"
                    value="${chk ? chk.qty : r.total_demanded}" min="1"
                    ${chk ? '' : 'disabled'}
                    oninput="updateNDChecked(${origIdx},'qty',this.value)">
                <div style="font-size:.65rem;color:#9ca3af;text-align:center;">qty</div>
            </div>
            <div class="vc">
                <input type="number" class="form-control form-control-sm nd-cost-vis" data-orig="${origIdx}"
                    value="${chk ? chk.cost : 0}" min="0" step="1000"
                    ${chk ? '' : 'disabled'}
                    placeholder="Unit cost"
                    oninput="updateNDChecked(${origIdx},'cost',this.value)">
                <div style="font-size:.65rem;color:#9ca3af;text-align:center;">unit cost</div>
            </div>
        </div>`;
    }).join(''));
    ndRendered += chunk.length;
    rebuildHiddens();
}

function toggleND(cb, origIdx) {
    const row     = document.getElementById(`ndrow-${origIdx}`);
    const qtyIn   = row.querySelector('.nd-qty-vis');
    const costIn  = row.querySelector('.nd-cost-vis');
    if (cb.checked) {
        ndChecked[origIdx] = { qty: parseInt(qtyIn.value)||ND_ALL[origIdx].total_demanded, cost: parseFloat(costIn.value)||0 };
        qtyIn.disabled  = false;
        costIn.disabled = false;
        row.style.background = '#f0fdf4';
    } else {
        delete ndChecked[origIdx];
        qtyIn.disabled  = true;
        costIn.disabled = true;
        row.style.background = '';
    }
    rebuildHiddens();
}

function updateNDChecked(origIdx, field, val) {
    if (!ndChecked[origIdx]) return;
    ndChecked[origIdx][field] = field === 'qty' ? parseInt(val)||0 : parseFloat(val)||0;
    rebuildHiddens();
}

function rebuildHiddens() {
    const container = document.getElementById('ndHiddens');
    const entries   = Object.entries(ndChecked);
    container.innerHTML = entries.map(([origIdx, vals], i) => {
        const r = ND_ALL[origIdx];
        return `<input type="hidden" name="new_items[${i}][product_id]" value="${r.product_id}">
                <input type="hidden" name="new_items[${i}][product_variant_id]" value="${r.variant_id||''}">
                <input type="hidden" name="new_items[${i}][quantity_ordered]" value="${vals.qty}">
                <input type="hidden" name="new_items[${i}][unit_cost]" value="${vals.cost}">`;
    }).join('');
}

function selectAllNew(val) {
    // For large lists, do this in chunks to avoid blocking
    const list = ndFiltered;
    if (val) {
        list.forEach((r, i) => {
            const origIdx = ND_ALL.indexOf(r);
            ndChecked[origIdx] = { qty: r.total_demanded, cost: 0 };
        });
    } else {
        ndChecked = {};
    }
    // Re-render visible rows
    const ndList = document.getElementById('ndList');
    ndList.innerHTML = '';
    ndRendered = 0;
    renderNDChunk();
    rebuildHiddens();
}

function filterNewDemand(q) {
    q = q.trim().toLowerCase();
    ndFiltered = q ? ND_ALL.filter(r =>
        (r.product_name+' '+(r.product_code||'')+' '+r.variant_label).toLowerCase().includes(q)
    ) : [...ND_ALL];
    const ndList = document.getElementById('ndList');
    ndList.innerHTML = '';
    ndRendered = 0;
    renderNDChunk();
    while (ndRendered < ndFiltered.length) renderNDChunk();
}

// Progressive scroll
document.getElementById('ndScroll')?.addEventListener('scroll', function() {
    if (ndRendered >= ndFiltered.length) return;
    if (this.scrollTop + this.clientHeight >= this.scrollHeight - 80) renderNDChunk();
});

// ── Existing items search ─────────────────────────────────────────────
function filterExisting(q) {
    q = q.trim().toLowerCase();
    document.querySelectorAll('.existing-row').forEach(r => {
        r.style.display = !q || (r.dataset.name||'').includes(q) ? '' : 'none';
    });
}

// ── Inline AJAX save for existing items ──────────────────────────────
let saveTimers = {};
function saveItem(itemId) {
    // Debounce — wait 600ms after last change before saving
    clearTimeout(saveTimers[itemId]);
    const statusEl = document.getElementById(`erow-status-${itemId}`);
    if (statusEl) statusEl.innerHTML = '<span class="text-muted small">…</span>';

    saveTimers[itemId] = setTimeout(async () => {
        const qty  = document.querySelector(`.ei-qty[data-id="${itemId}"]`)?.value || 0;
        const cost = document.querySelector(`.ei-cost[data-id="${itemId}"]`)?.value || 0;
        const row  = document.getElementById(`erow-${itemId}`);

        try {
            const res = await fetch(`/purchasing/{{ $purchasing->id }}/item/${itemId}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'Accept': 'application/json',
                },
                body: new URLSearchParams({ quantity_ordered: qty, unit_cost: cost })
            });
            const data = await res.json();
            if (data.ok) {
                const lineEl = document.getElementById(`erow-line-${itemId}`);
                if (lineEl) lineEl.innerHTML = `Line: <strong>Rp ${Math.round(data.line_total).toLocaleString('id-ID')}</strong>`;
                if (statusEl) statusEl.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
                if (row) { row.style.background='#f0fdf4'; setTimeout(()=>row.style.background='',1500); }
                setTimeout(() => { if(statusEl) statusEl.innerHTML=''; }, 3000);
            } else {
                if (statusEl) statusEl.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>';
            }
        } catch(e) {
            if (statusEl) statusEl.innerHTML = '<span class="text-danger small">Error</span>';
        }
    }, 600);
}

// Init
if (ND_ALL.length) renderNDChunk();
</script>
@endpush