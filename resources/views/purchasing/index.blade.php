@extends('layouts.app')
@section('title', 'Purchasing')
@section('page-title', 'Purchasing')

@push('styles')
<style>
.po-sticky-bar {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 200;
    background: #1e293b; color: #fff;
    padding: .65rem 1.25rem;
    display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap;
    box-shadow: 0 -4px 16px rgba(0,0,0,.2);
}
.po-sticky-bar .stat .val { font-size: 1rem; font-weight: 700; color: #38bdf8; }
.po-sticky-bar .stat .lbl { font-size: .65rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.po-sticky-bar .divider { width: 1px; height: 32px; background: #334155; flex-shrink:0; }
body { padding-bottom: 72px; }

.pgh {
    cursor: pointer; user-select: none;
    padding: .5rem .85rem;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 7px; margin-bottom: 4px;
    display: flex; align-items: center; gap: .55rem;
    transition: background .1s, border-color .1s; min-width: 0;
}
.pgh:hover  { background: #f0f9ff; border-color: #bae6fd; }
.pgh.open   { background: #eff6ff; border-color: #93c5fd; }
.pgh .ti    { flex-shrink:0; width:14px; font-size:.72rem; color:#94a3b8; transition:transform .18s; }
.pgh.open .ti { transform:rotate(90deg); color:#2563eb; }
.pgh .gn    { flex:1 1 0; font-size:.83rem; font-weight:600; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
.pgh .gc    { flex-shrink:0; font-size:.67rem; font-family:monospace; color:#6366f1; background:#eef2ff; border-radius:4px; padding:1px 5px; }
.pgh .gv    { flex-shrink:0; font-size:.7rem; color:#9ca3af; white-space:nowrap; min-width:65px; text-align:right; }
.pgh .gq    { flex-shrink:0; font-size:.74rem; font-weight:700; color:#fff; background:#2563eb; border-radius:20px; padding:2px 10px; white-space:nowrap; }
.pgh .gq.muted { background:#64748b; }
.pgb { display:none; padding: .2rem 0 .4rem 1rem; }
.pgb.open { display:block; }
.vrow { display:flex; align-items:center; gap:.5rem; padding:.35rem .5rem; border-radius:4px; font-size:.82rem; }
.vrow:hover { background:#f8fafc; }
.vrow .vl { flex:1; color:#374151; }
.vrow .vd { width:52px; text-align:center; }
.vrow .vg { width:48px; text-align:center; font-size:.75rem; }
.vrow input[type=number] { width:82px; }
.vrow .vc { width:120px; }
.po-panel { display:none; border-top: 2px solid #3b82f6; }
.po-panel.active { display:block; }
.search-hidden { display:none !important; }

/* Skeleton loader */
.skel-line { height:44px; background:linear-gradient(90deg,#f0f0f0 25%,#e8e8e8 50%,#f0f0f0 75%);
    background-size:200% 100%; animation:sk 1.2s infinite; border-radius:7px; margin-bottom:4px; }
@keyframes sk { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
</style>
@endpush

@section('content')

<form class="d-flex gap-2 mb-3 align-items-center" id="tripForm">
    <select name="trip_id" id="tripSelect" class="form-select form-select-sm" style="width:300px;">
        <option value="">Select trip…</option>
        @foreach($trips as $trip)
            <option value="{{ $trip->id }}" {{ $selectedTrip?->id == $trip->id ? 'selected' : '' }}>
                {{ $trip->name }}
            </option>
        @endforeach
    </select>
    <span id="loadingSpinner" class="spinner-border spinner-border-sm text-primary ms-2" style="display:none;"></span>
</form>

@if($selectedTrip)
<div class="row g-3">
    <div class="col-lg-8">
        {{-- Demand loads here asynchronously --}}
        <div id="demandContainer">
            {{-- Skeleton --}}
            @for($s=0;$s<8;$s++)
            <div class="skel-line"></div>
            @endfor
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card" style="position:sticky;top:1rem;">
            <div class="card-header bg-white py-3 fw-semibold">
                Purchase Orders
                <span class="badge bg-secondary ms-1">{{ $purchaseOrders->count() }}</span>
            </div>
            <ul class="list-group list-group-flush" style="max-height:80vh;overflow-y:auto;">
                @forelse($purchaseOrders as $po)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="font-monospace small fw-semibold">{{ $po->po_number }}</div>
                        <div class="text-muted" style="font-size:.75rem;">
                            {{ $po->supplier?->name ?? 'No supplier' }} · {{ $po->items_count }} item(s)
                        </div>
                        <div class="text-muted" style="font-size:.75rem;">
                            Rp {{ number_format($po->total_amount,0,',','.') }}
                        </div>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-1">
                        <span class="badge {{ match($po->status){
                            'arrived'=>'bg-success','confirmed'=>'bg-primary',
                            'submitted'=>'bg-warning text-dark',default=>'bg-secondary'} }}">
                            {{ ucfirst($po->status) }}
                        </span>
                        <a href="{{ route('purchasing.show',$po) }}" class="btn btn-sm btn-outline-secondary py-0 px-2">View</a>
                    </div>
                </li>
                @empty
                <li class="list-group-item text-center text-muted small py-3">No purchase orders yet</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

{{-- Sticky bottom bar --}}
<div class="po-sticky-bar" id="stickyBar" style="display:none;">
    <div class="stat"><div class="val" id="stkVariants">0</div><div class="lbl">Variants</div></div>
    <div class="divider"></div>
    <div class="stat"><div class="val" id="stkQty">0</div><div class="lbl">Total Qty</div></div>
    <div class="divider"></div>
    <div class="stat"><div class="val" id="stkCost">Rp 0</div><div class="lbl">Purchase Cost</div></div>
    <div class="divider"></div>
    <div class="stat"><div class="val" id="stkSupplier" style="font-size:.82rem;color:#e2e8f0;">—</div><div class="lbl">Supplier</div></div>
    <div class="ms-auto d-flex gap-2">
        <button type="button" class="btn btn-outline-light btn-sm" onclick="cancelPO()"><i class="bi bi-x me-1"></i>Cancel</button>
        <button type="button" class="btn btn-success btn-sm px-4" onclick="submitPO()"><i class="bi bi-check-circle me-1"></i>Create Purchase Order</button>
    </div>
</div>

@else
<div class="card p-5 text-center text-muted">
    <i class="bi bi-box-seam fs-1 mb-3 d-block"></i>
    Select a trip above to view purchasing demand.
</div>
@endif

@endsection

@push('scripts')
<script>
'use strict';

const TRIP_ID    = {{ $selectedTrip?->id ?? 'null' }};
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
const CAN_PURCHASE_EDIT = {{ auth()->user()->hasPermission('purchasing.edit') ? 'true' : 'false' }};
let   DEMAND     = [];
let   activePOKey = null;

// ── On load: fetch demand asynchronously ─────────────────────────────
if (TRIP_ID) {
    fetchDemand(TRIP_ID);
}

// Trip selector
document.getElementById('tripSelect').addEventListener('change', function() {
    window.location.href = '/purchasing?trip_id=' + this.value;
});

async function fetchDemand(tripId) {
    document.getElementById('loadingSpinner').style.display = '';
    try {
        const res  = await fetch(`/purchasing-demand?trip_id=${tripId}&_=${Date.now()}`, {
            headers: { 'X-CSRF-TOKEN': CSRF_TOKEN, 'Accept': 'application/json' }
        });
        DEMAND = await res.json();
        renderDemand();
    } catch(e) {
        document.getElementById('demandContainer').innerHTML =
            '<div class="alert alert-danger">Failed to load demand data. <button class="btn btn-sm btn-outline-danger ms-2" onclick="fetchDemand('+tripId+')">Retry</button></div>';
    } finally {
        document.getElementById('loadingSpinner').style.display = 'none';
    }
}

// ── Render demand from JSON ──────────────────────────────────────────
function renderDemand() {
    const container = document.getElementById('demandContainer');

    if (!DEMAND.length) {
        container.innerHTML = `<div class="card p-4 text-center text-muted">
            <i class="bi bi-check2-circle fs-1 mb-2 d-block text-success"></i>
            <div class="fw-semibold">All demand is covered by confirmed Purchase Orders.</div>
        </div>`;
        return;
    }

    container.innerHTML = DEMAND.map(group => {
        const key        = 'sup_' + (group.supplier_id ?? 'none');
        const byProduct  = groupByProduct(group.rows);
        const totalQty   = group.rows.reduce((s,r) => s + r.total_demanded, 0);
        const totalVars  = group.rows.length;
        const totalProds = Object.keys(byProduct).length;

        const hasDraftPOs = group.active_po !== null && group.active_po !== undefined;
        const draftWarn = hasDraftPOs ? `
            <div class="mt-2 p-2 rounded d-flex align-items-center gap-2"
                 style="background:#fff7ed;border:1px solid #fed7aa;font-size:.75rem;color:#92400e;">
                <i class="bi bi-info-circle-fill text-warning"></i>
                <span>New demand will be added to existing PO
                <a href="/purchasing/${group.active_po.id}"><strong>${escH(group.active_po.po_number)}</strong></a>
                (<span class="badge ${group.active_po.status==='submitted'?'bg-warning text-dark':'bg-secondary'}">${cap(group.active_po.status)}</span>).
                Click <strong>Add to ${escH(group.active_po.po_number)}</strong> to review and include new items.</span>
            </div>` : '';

        const accordionHtml = Object.entries(byProduct).map(([productId, rows]) => {
            const first   = rows[0];
            const groupQty = rows.reduce((s,r) => s + r.total_demanded, 0);
            const gId     = key + '_p' + productId;
            return `<div class="product-group" id="group-${gId}"
                         data-group-id="${gId}"
                         data-product-name="${escH((first.product_name+' '+(first.product_code||'')).toLowerCase())}">
                <div class="pgh" onclick="toggleDemandGroup('${gId}')">
                    <i class="bi bi-chevron-right ti"></i>
                    <span class="gn">${escH(first.product_name)}</span>
                    ${first.product_code ? `<span class="gc">${escH(first.product_code)}</span>` : ''}
                    <span class="gv">${rows.length} var.</span>
                    <span class="gq">${groupQty}</span>
                </div>
                <div class="pgb" id="body-${gId}" data-loaded="0"
                     data-rows="${escAttr(JSON.stringify(rows))}"></div>
            </div>`;
        }).join('');

        return `<div class="card mb-4" id="card-${key}" data-supplier-key="${key}" data-supplier-name="${escH(group.supplier_name)}">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <span class="fw-bold">${escH(group.supplier_name)}</span>
                        <span class="badge bg-light text-secondary border ms-2">${totalProds} products</span>
                        <span class="badge bg-light text-secondary border ms-1">${totalVars} variants</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" onclick="expandAll('${key}')">
                            <i class="bi bi-chevron-double-down me-1"></i>Expand all
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="collapseAll('${key}')">
                            <i class="bi bi-chevron-double-up me-1"></i>Collapse
                        </button>
                        ${group.active_po
                            ? `<button class="btn btn-sm btn-warning" onclick="syncAndEdit(${group.active_po.id}, '${escH(group.active_po.po_number)}')">
                                <i class="bi bi-pencil me-1"></i>Add to ${escH(group.active_po.po_number)}
                              </button>`
                            : (CAN_PURCHASE_EDIT ? `<button class="btn btn-sm btn-primary" onclick="openPOPanel('${key}')">
                                <i class="bi bi-file-earmark-plus me-1"></i>Create PO
                              </button>` : '')
                        }
                    </div>
                </div>
                <div class="d-flex gap-4 mt-2 pt-2 border-top flex-wrap">
                    <div><div class="fw-bold" style="color:#0369a1;">${totalQty.toLocaleString('id-ID')}</div><div style="font-size:.67rem;color:#6b7280;text-transform:uppercase;">Total Qty</div></div>
                    <div><div class="fw-bold" style="color:#0369a1;">${totalVars}</div><div style="font-size:.67rem;color:#6b7280;text-transform:uppercase;">Variants</div></div>
                </div>
                ${draftWarn}
                <div class="mt-2">
                    <input type="text" class="form-control form-control-sm"
                        placeholder="Search product or variant…"
                        oninput="searchAccordion('${key}', this.value)">
                </div>
            </div>
            <div class="card-body py-2" id="accordion-${key}">${accordionHtml}</div>
            <div class="po-panel" id="po-panel-${key}">
                <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                    <span class="fw-semibold text-primary"><i class="bi bi-file-earmark-plus me-1"></i>Create PO — ${escH(group.supplier_name)}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closePOPanel('${key}')"><i class="bi bi-x"></i> Cancel</button>
                </div>
                <div class="card-body" id="po-body-${key}"></div>
            </div>
        </div>`;
    }).join('');
}

// ── Helpers ──────────────────────────────────────────────────────────
function groupByProduct(rows) {
    const g = {};
    rows.forEach(r => { if (!g[r.product_id]) g[r.product_id] = []; g[r.product_id].push(r); });
    return g;
}
function escH(s)    { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Sync PO with latest demand server-side, then redirect to Edit PO
function syncAndEdit(poId, poNumber) {
    if (!confirm(`Update "${poNumber}" with all current demand?\n\nItem quantities will be updated to match total orders. You can then set unit costs.`)) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `/purchasing/${poId}/sync-demand`;
    form.innerHTML = `<input type="hidden" name="_token" value="${document.querySelector('meta[name=csrf-token]').content}">`;
    document.body.appendChild(form);
    form.submit();
}
function escAttr(s) { return String(s||'').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function cap(s)     { return s ? s[0].toUpperCase()+s.slice(1) : s; }

// ── Accordion ────────────────────────────────────────────────────────
function toggleDemandGroup(groupId) {
    const header = document.querySelector(`#group-${groupId} .pgh`);
    const body   = document.getElementById(`body-${groupId}`);
    if (!header || !body) return;
    const opening = !body.classList.contains('open');
    body.classList.toggle('open', opening);
    header.classList.toggle('open', opening);
    if (opening && body.dataset.loaded === '0') {
        const rows = JSON.parse(body.dataset.rows || '[]');
        body.innerHTML = rows.map(r => {
            const gap    = r.supplier_stock - r.total_demanded;
            const gapCls = gap < 0 ? 'text-danger fw-bold' : 'text-success';
            return `<div class="vrow">
                <span class="vl">${escH(r.variant_label||'Default')}</span>
                <span class="vd"><span class="badge bg-primary">${r.total_demanded}</span></span>
                <span class="vg ${gapCls}">${gap>=0?'+':''}${gap}</span>
            </div>`;
        }).join('');
        body.dataset.loaded = '1';
    }
}
function expandAll(k) {
    document.querySelectorAll(`#accordion-${k} .product-group`).forEach(g => {
        const body = document.getElementById(`body-${g.dataset.groupId}`);
        if (body && !body.classList.contains('open')) toggleDemandGroup(g.dataset.groupId);
    });
}
function collapseAll(k) {
    document.querySelectorAll(`#accordion-${k} .pgh.open`).forEach(h => h.click());
}

// ── PO Panel ─────────────────────────────────────────────────────────
function openPOPanel(key) {
    if (activePOKey && activePOKey !== key) closePOPanel(activePOKey);
    activePOKey = key;
    const panel = document.getElementById(`po-panel-${key}`);
    const body  = document.getElementById(`po-body-${key}`);
    if (!panel) return;
    if (body && body.dataset.built !== '1') { buildPOForm(key, body); body.dataset.built = '1'; }
    panel.classList.add('active');
    setTimeout(() => panel.scrollIntoView({ behavior:'smooth', block:'start' }), 60);
    document.getElementById('stkSupplier').textContent = document.getElementById(`card-${key}`)?.dataset?.supplierName || '—';
    document.getElementById('stickyBar').style.display = 'flex';
    updateStickyBar();
}

function buildPOForm(key, container) {
    const group = DEMAND.find(g => 'sup_'+(g.supplier_id??'none') === key);
    if (!group) return;
    const byProduct = groupByProduct(group.rows);
    let html = `<form method="POST" action="/purchasing" id="po-form-${key}" onsubmit="return confirmPO()">
        <input type="hidden" name="_token" value="${CSRF_TOKEN}">
        <input type="hidden" name="trip_id" value="${TRIP_ID}">
        <input type="hidden" name="supplier_id" value="${group.supplier_id??''}">
        <div class="row g-2 mb-3">
            <div class="col-md-3"><label class="form-label small fw-semibold">Purchase Date</label>
                <input type="date" name="purchased_at" class="form-control form-control-sm" value="${new Date().toISOString().slice(0,10)}" required></div>
            <div class="col-md-5"><label class="form-label small fw-semibold">Notes</label>
                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional…"></div>
        </div>
        <div class="mb-2">
            <input type="text" class="form-control form-control-sm" placeholder="Search product in PO form…"
                oninput="searchPOAccordion('${key}', this.value)">
        </div>
        <div id="po-accordion-${key}">`;

    let fi = 0;
    for (const [productId, rows] of Object.entries(byProduct)) {
        const first  = rows[0];
        const gQty   = rows.reduce((s,r) => s+r.total_demanded, 0);
        const pgId   = `po_${key}_p${productId}`;
        html += `<div class="product-group po-pg" id="pg-${pgId}"
                      data-product-name="${escH((first.product_name+' '+(first.product_code||'')).toLowerCase())}">
            <div class="pgh open" onclick="togglePOGroup('${pgId}')">
                <i class="bi bi-chevron-right ti"></i>
                <span class="gn">${escH(first.product_name)}</span>
                ${first.product_code ? `<span class="gc">${escH(first.product_code)}</span>` : ''}
                <span class="gv">${rows.length} var.</span>
                <span class="gq muted po-gq" id="pogq-${pgId}">${gQty}</span>
            </div>
            <div class="pgb open" id="pob-${pgId}">`;
        rows.forEach(r => {
            html += `<div class="vrow">
                <span class="vl">${escH(r.variant_label||'Default')}<br>
                    <span style="font-size:.68rem;color:#9ca3af">Stock: ${r.supplier_stock}</span></span>
                <input type="hidden" name="items[${fi}][product_id]" value="${r.product_id}">
                <input type="hidden" name="items[${fi}][product_variant_id]" value="${r.variant_id??''}">
                <div>
                    <input type="number" name="items[${fi}][quantity_ordered]"
                        class="form-control form-control-sm po-qty" data-group="${pgId}"
                        value="${r.total_demanded}" min="0" required
                        oninput="updateStickyBar();updatePOGroupQty('${pgId}')">
                    <div style="font-size:.65rem;color:#9ca3af;text-align:center;">demand:${r.total_demanded}</div>
                </div>
                <div class="vc">
                    <input type="number" name="items[${fi}][unit_cost]"
                        class="form-control form-control-sm po-cost"
                        value="0" step="1000" min="0" required placeholder="Supplier cost"
                        oninput="updateStickyBar()">
                    <div style="font-size:.65rem;color:#9ca3af;text-align:center;">unit cost</div>
                </div>
            </div>`;
            fi++;
        });
        html += `</div></div>`;
    }
    html += `</div></form>`;
    container.innerHTML = html;
}

function togglePOGroup(pgId) {
    const h = document.querySelector(`#pg-${pgId} .pgh`);
    const b = document.getElementById(`pob-${pgId}`);
    if (!h||!b) return;
    const open = !b.classList.contains('open');
    b.classList.toggle('open', open); h.classList.toggle('open', open);
}
function updatePOGroupQty(pgId) {
    const t = [...document.querySelectorAll(`.po-qty[data-group="${pgId}"]`)].reduce((s,i)=>s+(parseInt(i.value)||0),0);
    const el = document.getElementById(`pogq-${pgId}`); if(el) el.textContent = t;
}
function closePOPanel(key) {
    document.getElementById(`po-panel-${key}`)?.classList.remove('active');
    if (activePOKey === key) { activePOKey = null; document.getElementById('stickyBar').style.display = 'none'; }
}
function cancelPO() { if (activePOKey) closePOPanel(activePOKey); }
function submitPO() { if (activePOKey) document.getElementById(`po-form-${activePOKey}`)?.requestSubmit(); }
function confirmPO() {
    return confirm(`Create PO for ${document.getElementById('stkSupplier').textContent}?\n\nTotal Qty: ${document.getElementById('stkQty').textContent}\nCost: ${document.getElementById('stkCost').textContent}\n\nOK to confirm.`);
}

// ── Sticky bar ────────────────────────────────────────────────────────
function updateStickyBar() {
    if (!activePOKey) return;
    const qs = document.querySelectorAll(`#po-form-${activePOKey} .po-qty`);
    const cs = document.querySelectorAll(`#po-form-${activePOKey} .po-cost`);
    let tQ=0, tC=0, tV=0;
    qs.forEach((q,i) => { const v=parseInt(q.value)||0; tQ+=v; tC+=v*(parseFloat(cs[i]?.value)||0); if(v>0)tV++; });
    document.getElementById('stkVariants').textContent = tV;
    document.getElementById('stkQty').textContent      = tQ.toLocaleString('id-ID');
    document.getElementById('stkCost').textContent     = 'Rp '+Math.round(tC).toLocaleString('id-ID');
}

// ── Search ────────────────────────────────────────────────────────────
function searchAccordion(k, q) {
    q = q.trim().toLowerCase();
    document.querySelectorAll(`#accordion-${k} .product-group`).forEach(g => {
        const match = !q || (g.dataset.productName||'').includes(q);
        g.classList.toggle('search-hidden', !match);
        if (match && q) {
            const b = document.getElementById(`body-${g.dataset.groupId}`);
            const h = g.querySelector('.pgh');
            if (b && !b.classList.contains('open')) { b.classList.add('open'); h?.classList.add('open'); }
            if (b && b.dataset.loaded==='0') toggleDemandGroup(g.dataset.groupId);
        }
    });
}
function searchPOAccordion(k, q) {
    q = q.trim().toLowerCase();
    document.querySelectorAll(`#po-accordion-${k} .po-pg`).forEach(g => {
        g.classList.toggle('search-hidden', !(!q || (g.dataset.productName||'').includes(q)));
    });
}
</script>
@endpush