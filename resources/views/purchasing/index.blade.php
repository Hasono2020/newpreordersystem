@extends('layouts.app')
@section('title', 'Purchasing')
@section('page-title', 'Purchasing')

@push('styles')
<style>
/* ── Sticky bottom bar ───────────────────────────── */
.po-sticky-bar {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    z-index: 200;
    background: #1e293b;
    color: #fff;
    padding: .75rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    box-shadow: 0 -4px 16px rgba(0,0,0,.18);
}
.po-sticky-bar .stat { text-align: center; }
.po-sticky-bar .stat .val { font-size: 1.1rem; font-weight: 700; color: #38bdf8; }
.po-sticky-bar .stat .lbl { font-size: .7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.po-sticky-bar .divider { width: 1px; height: 36px; background: #334155; }
.po-sticky-bar .ms-auto { margin-left: auto !important; }
body { padding-bottom: 80px; }

/* ── PO creation panel ───────────────────────────── */
.po-panel { display: none; }
.po-panel.active { display: block; }

/* ── Accordion ───────────────────────────────────── */
.product-group-header {
    cursor: pointer;
    user-select: none;
    padding: .55rem .9rem;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 7px;
    margin-bottom: 4px;
    display: grid;
    grid-template-columns: 18px 1fr auto;
    align-items: center;
    gap: .6rem;
    transition: background .1s, border-color .1s;
}
.product-group-header:hover { background: #f0f9ff; border-color: #bae6fd; }
.product-group-header.open  { background: #f0f9ff; border-color: #7dd3fc; }
.product-group-header .toggle-icon { transition: transform .2s; color: #94a3b8; font-size:.75rem; justify-self:center; }
.product-group-header.open .toggle-icon { transform: rotate(90deg); color: #0ea5e9; }
.product-group-header .gh-name { font-size: .83rem; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.product-group-header .gh-code { font-size: .7rem; font-family: monospace; color: #6366f1; background: #eef2ff; border-radius: 4px; padding: 1px 5px; white-space: nowrap; }
.product-group-header .gh-meta { display: flex; align-items: center; gap: .5rem; }
.product-group-header .gh-qty  { font-size: .78rem; font-weight: 700; color: #fff; background: #2563eb; border-radius: 12px; padding: 2px 10px; white-space: nowrap; }
.product-group-header .gh-vars { font-size: .72rem; color: #94a3b8; white-space: nowrap; min-width: 70px; text-align: right; }
.product-group-body { display: none; padding: .25rem 0 .5rem 1.2rem; }
.product-group-body.open { display: block; }

/* ── variant row ─────────────────────────────────── */
.variant-row { display: flex; align-items: center; gap: .5rem; padding: .4rem .5rem; border-radius: 4px; }
.variant-row:hover { background: #f8fafc; }
.variant-row .v-label { min-width: 180px; font-size: .83rem; }
.variant-row .v-demand { min-width: 60px; text-align:center; }
.variant-row .v-gap { min-width: 55px; text-align:center; font-size:.8rem; }
.variant-row input[type=number] { width: 80px; }

/* ── Summary card ────────────────────────────────── */
.summary-card { background:#f0f9ff; border:1.5px solid #bae6fd; border-radius:10px; padding:1rem; margin-bottom:1rem; }
.summary-card .s-val { font-size:1.25rem; font-weight:700; color:#0369a1; }
.summary-card .s-lbl { font-size:.72rem; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; }

/* ── Search highlight ────────────────────────────── */
.search-hidden { display: none !important; }
</style>
@endpush

@section('content')

{{-- Trip selector --}}
<form class="d-flex gap-2 mb-3 align-items-center">
    <select name="trip_id" class="form-select form-select-sm" style="width:300px;" onchange="this.form.submit()">
        <option value="">Select trip…</option>
        @foreach($trips as $trip)
            <option value="{{ $trip->id }}" {{ $selectedTrip?->id == $trip->id ? 'selected' : '' }}>
                {{ $trip->name }}
            </option>
        @endforeach
    </select>
</form>

@if($selectedTrip)

{{-- ── Main layout ── --}}
<div class="row g-3">

    {{-- LEFT: Demand grouped by supplier --}}
    <div class="col-lg-8">

        @forelse($demandBySupplier as $supplierId => $group)
        @php
            $supplierName   = $group['supplier_name'];
            $supplierRealId = $group['supplier_id'];
            $rows           = $group['rows'];
            $safeKey        = 'sup_' . ($supplierRealId ?? 'none');

            // Group rows by product_id for accordion
            $byProduct = collect($rows)->groupBy('product_id');
            $totalQty  = collect($rows)->sum('total_demanded');
            $totalVariants = count($rows);
            $totalProducts = $byProduct->count();
            $totalCost = collect($rows)->sum(fn($r) => $r['total_demanded'] * $r['unit_cost']);
        @endphp

        <div class="card mb-4" id="card-{{ $safeKey }}">
            {{-- Supplier header --}}
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-bold fs-6">{{ $supplierName }}</span>
                        <span class="badge bg-light text-secondary border ms-2">{{ $totalProducts }} products</span>
                        <span class="badge bg-light text-secondary border ms-1">{{ $totalVariants }} variants</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" onclick="expandAll('{{ $safeKey }}')">
                            <i class="bi bi-chevron-double-down me-1"></i>Expand all
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="collapseAll('{{ $safeKey }}')">
                            <i class="bi bi-chevron-double-up me-1"></i>Collapse all
                        </button>
                        <button class="btn btn-sm btn-primary" onclick="openPOPanel('{{ $safeKey }}')">
                            <i class="bi bi-file-earmark-plus me-1"></i>Create PO
                        </button>
                    </div>
                </div>

                {{-- Summary strip --}}
                <div class="d-flex gap-4 mt-2 pt-2 border-top">
                    <div class="summary-strip-item">
                        <div class="s-val" style="font-size:.95rem;font-weight:700;color:#0369a1;">{{ number_format($totalQty) }}</div>
                        <div class="s-lbl" style="font-size:.68rem;color:#6b7280;text-transform:uppercase;">Total Qty</div>
                    </div>
                    <div class="summary-strip-item">
                        <div class="s-val" style="font-size:.95rem;font-weight:700;color:#0369a1;">{{ $totalVariants }}</div>
                        <div class="s-lbl" style="font-size:.68rem;color:#6b7280;text-transform:uppercase;">Variants</div>
                    </div>
                    <div class="summary-strip-item">
                        <div class="s-val" style="font-size:.95rem;font-weight:700;color:#0369a1;">Rp {{ number_format($totalCost, 0, ',', '.') }}</div>
                        <div class="s-lbl" style="font-size:.68rem;color:#6b7280;text-transform:uppercase;">Est. Cost</div>
                    </div>
                </div>

                {{-- Search --}}
                <div class="mt-2">
                    <input type="text" class="form-control form-control-sm supplier-search"
                        data-key="{{ $safeKey }}"
                        placeholder="Search product or variant…"
                        oninput="searchProducts(this)">
                </div>
            </div>

            {{-- Accordion product groups --}}
            <div class="card-body py-2" id="accordion-{{ $safeKey }}">
                @foreach($byProduct as $productId => $productRows)
                @php
                    $firstRow    = $productRows->first();
                    $productName = $firstRow['product_name'];
                    $productCode = $firstRow['product_code'] ?? '';
                    $groupQty    = $productRows->sum('total_demanded');
                    $groupId     = $safeKey . '_p' . $productId;
                @endphp

                <div class="product-group mb-1" data-product-name="{{ strtolower($productName) }} {{ strtolower($productCode) }}" id="group-{{ $groupId }}">
                    {{-- Group header --}}
                    <div class="product-group-header" onclick="toggleGroup('{{ $groupId }}')">
                        <i class="bi bi-chevron-right toggle-icon"></i>
                        <span class="gh-name">{{ $productName }}</span>
                        <div class="gh-meta">
                            @if($productCode)
                                <span class="gh-code">{{ $productCode }}</span>
                            @endif
                            <span class="gh-vars">{{ $productRows->count() }} variant(s)</span>
                            <span class="gh-qty">{{ $groupQty }} pcs</span>
                        </div>
                    </div>

                    {{-- Group body: variant rows --}}
                    <div class="product-group-body" id="body-{{ $groupId }}">
                        @foreach($productRows as $row)
                        @php $gap = $row['supplier_stock'] - $row['total_demanded']; @endphp
                        <div class="variant-row" data-variant="{{ strtolower($row['variant_label'] ?? '') }}">
                            <div class="v-label text-muted">
                                {{ $row['variant_label'] ?? 'Default' }}
                            </div>
                            <div class="v-demand">
                                <span class="badge bg-primary">{{ $row['total_demanded'] }}</span>
                                <div style="font-size:.65rem;color:#9ca3af;">demand</div>
                            </div>
                            <div class="v-gap">
                                <span class="{{ $gap < 0 ? 'text-danger fw-bold' : 'text-success' }}">
                                    {{ $gap >= 0 ? '+' : '' }}{{ $gap }}
                                </span>
                                <div style="font-size:.65rem;color:#9ca3af;">gap</div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>

            {{-- PO Creation panel — inline below the demand table --}}
            <div class="po-panel border-top" id="po-panel-{{ $safeKey }}">
                <form method="POST" action="{{ route('purchasing.store') }}" class="po-create-form"
                      id="po-form-{{ $safeKey }}"
                      onsubmit="return confirmPO(this)">
                    @csrf
                    <input type="hidden" name="trip_id"     value="{{ $selectedTrip->id }}">
                    <input type="hidden" name="supplier_id" value="{{ $supplierRealId }}">

                    <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold text-primary"><i class="bi bi-file-earmark-plus me-1"></i>Create Purchase Order — {{ $supplierName }}</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closePOPanel('{{ $safeKey }}')">
                            <i class="bi bi-x"></i> Cancel
                        </button>
                    </div>

                    <div class="card-body">
                        {{-- PO header fields --}}
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Purchase Date</label>
                                <input type="date" name="purchased_at" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Notes</label>
                                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional notes…">
                            </div>
                        </div>

                        {{-- Search within PO form --}}
                        <div class="mb-2">
                            <input type="text" class="form-control form-control-sm po-form-search"
                                data-form-key="{{ $safeKey }}"
                                placeholder="Search product or variant in PO form…"
                                oninput="searchPOForm(this)">
                        </div>

                        {{-- PO items: accordion by product --}}
                        <div id="po-items-{{ $safeKey }}">
                            @foreach($byProduct as $productId => $productRows)
                            @php
                                $firstRow    = $productRows->first();
                                $pName       = $firstRow['product_name'];
                                $pCode       = $firstRow['product_code'] ?? '';
                                $pGroupQty   = $productRows->sum('total_demanded');
                                $poGroupId   = 'po_' . $safeKey . '_p' . $productId;
                            @endphp
                            <div class="product-group mb-1 po-product-group"
                                 data-product-name="{{ strtolower($pName) }} {{ strtolower($pCode) }}"
                                 id="po-group-{{ $poGroupId }}">

                                <div class="product-group-header open" onclick="toggleGroup('{{ $poGroupId }}')">
                                    <i class="bi bi-chevron-right toggle-icon"></i>
                                    <span class="gh-name">{{ $pName }}</span>
                                    <div class="gh-meta">
                                        @if($pCode)
                                            <span class="gh-code">{{ $pCode }}</span>
                                        @endif
                                        <span class="gh-vars">{{ $productRows->count() }} variant(s)</span>
                                        <span class="gh-qty po-group-qty" data-group="{{ $poGroupId }}">{{ $pGroupQty }} pcs</span>
                                    </div>
                                            </div>

                                <div class="product-group-body open" id="body-{{ $poGroupId }}">
                                    @foreach($productRows as $ri => $row)
                                    @php
                                        $flatIdx = $loop->parent->index * 1000 + $loop->index;
                                    @endphp
                                    <div class="variant-row po-variant-row"
                                         data-group="{{ $poGroupId }}"
                                         data-variant="{{ strtolower($row['variant_label'] ?? '') }}">
                                        <input type="hidden" name="items[{{ $flatIdx }}][product_id]"         value="{{ $row['product_id'] }}">
                                        <input type="hidden" name="items[{{ $flatIdx }}][product_variant_id]" value="{{ $row['variant_id'] ?? '' }}">

                                        <div class="v-label">
                                            <span class="small">{{ $row['variant_label'] ?? 'Default' }}</span>
                                            <div style="font-size:.65rem;color:#9ca3af;">Stock: {{ $row['supplier_stock'] }}</div>
                                        </div>

                                        <div>
                                            <input type="number"
                                                name="items[{{ $flatIdx }}][quantity_ordered]"
                                                class="form-control form-control-sm po-qty-input"
                                                data-group="{{ $poGroupId }}"
                                                value="{{ $row['total_demanded'] }}"
                                                min="0" required
                                                oninput="updateStickyBar(); updateGroupQty('{{ $poGroupId }}')">
                                            <div style="font-size:.65rem;color:#9ca3af;text-align:center;">demand: {{ $row['total_demanded'] }}</div>
                                        </div>

                                        <div>
                                            <input type="number"
                                                name="items[{{ $flatIdx }}][unit_cost]"
                                                class="form-control form-control-sm po-cost-input"
                                                value="{{ $row['unit_cost'] }}"
                                                step="1000" min="0" required
                                                style="width:110px;"
                                                oninput="updateStickyBar()">
                                            <div style="font-size:.65rem;color:#9ca3af;text-align:center;">unit cost</div>
                                        </div>

                                        <div class="v-gap small">
                                            @php $gap = $row['supplier_stock'] - $row['total_demanded']; @endphp
                                            <span class="{{ $gap < 0 ? 'text-danger' : 'text-success' }}">
                                                {{ $gap >= 0 ? '+' : '' }}{{ $gap }}
                                            </span>
                                            <div style="font-size:.65rem;color:#9ca3af;">gap</div>
                                        </div>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </form>
            </div>

        </div>{{-- end card --}}
        @empty
        <div class="card p-4 text-center text-muted">
            @if($purchaseOrders->where('status', '!=', 'arrived')->count() > 0)
                <i class="bi bi-check2-circle fs-1 mb-2 d-block text-success"></i>
                <div class="fw-semibold">All suppliers have active Purchase Orders for this trip.</div>
            @else
                <i class="bi bi-inbox fs-1 mb-2 d-block"></i>
                No active order items for this trip yet.
            @endif
        </div>
        @endforelse
    </div>

    {{-- RIGHT: Existing POs --}}
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
                            {{ $po->supplier?->name ?? 'No supplier' }} · {{ $po->items->count() }} item(s)
                        </div>
                        <div class="text-muted" style="font-size:.75rem;">
                            Rp {{ number_format($po->total_amount, 0, ',', '.') }}
                        </div>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-1">
                        <span class="badge {{ match($po->status) {
                            'arrived'   => 'bg-success',
                            'confirmed' => 'bg-primary',
                            'submitted' => 'bg-warning text-dark',
                            default     => 'bg-secondary'
                        } }}">{{ ucfirst($po->status) }}</span>
                        <a href="{{ route('purchasing.show', $po) }}" class="btn btn-sm btn-outline-secondary py-0 px-2">View</a>
                    </div>
                </li>
                @empty
                <li class="list-group-item text-center text-muted small py-3">No purchase orders yet</li>
                @endforelse
            </ul>
        </div>
    </div>

</div>{{-- end row --}}

{{-- ── Sticky bottom bar (visible when a PO form is open) ── --}}
<div class="po-sticky-bar" id="stickyBar" style="display:none;">
    <div class="stat">
        <div class="val" id="stickyVariants">0</div>
        <div class="lbl">Variants</div>
    </div>
    <div class="divider"></div>
    <div class="stat">
        <div class="val" id="stickyQty">0</div>
        <div class="lbl">Total Qty</div>
    </div>
    <div class="divider"></div>
    <div class="stat">
        <div class="val" id="stickyCost">Rp 0</div>
        <div class="lbl">Est. Cost</div>
    </div>
    <div class="divider"></div>
    <div class="stat">
        <div class="val" id="stickySupplier" style="font-size:.85rem;color:#e2e8f0;">—</div>
        <div class="lbl">Supplier</div>
    </div>
    <div class="ms-auto d-flex gap-2">
        <button type="button" class="btn btn-outline-light btn-sm" onclick="cancelActivePO()">
            <i class="bi bi-x me-1"></i>Cancel
        </button>
        <button type="button" class="btn btn-success btn-sm px-4" onclick="submitActivePO()" id="stickySubmitBtn">
            <i class="bi bi-check-circle me-1"></i>Create Purchase Order
        </button>
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
let activePOKey = null;

// ── Accordion ────────────────────────────────────────────────────────
function toggleGroup(groupId) {
    const header = document.querySelector(`[onclick="toggleGroup('${groupId}')"]`);
    const body   = document.getElementById(`body-${groupId}`);
    if (!header || !body) return;
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    header.classList.toggle('open', !isOpen);
}

function expandAll(supKey) {
    document.querySelectorAll(`#accordion-${supKey} .product-group-body`).forEach(b => b.classList.add('open'));
    document.querySelectorAll(`#accordion-${supKey} .product-group-header`).forEach(h => h.classList.add('open'));
}

function collapseAll(supKey) {
    document.querySelectorAll(`#accordion-${supKey} .product-group-body`).forEach(b => b.classList.remove('open'));
    document.querySelectorAll(`#accordion-${supKey} .product-group-header`).forEach(h => h.classList.remove('open'));
}

// ── PO Panel open/close ───────────────────────────────────────────────
function openPOPanel(key) {
    if (activePOKey && activePOKey !== key) closePOPanel(activePOKey);
    activePOKey = key;
    const panel = document.getElementById(`po-panel-${key}`);
    if (panel) panel.classList.add('active');
    // scroll to panel
    setTimeout(() => panel?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
    document.getElementById('stickyBar').style.display = 'flex';
    // set supplier name in bar
    const card = document.getElementById(`card-${key}`);
    const supName = card?.querySelector('.fw-bold.fs-6')?.textContent?.trim() || '—';
    document.getElementById('stickySupplier').textContent = supName;
    updateStickyBar();
}

function closePOPanel(key) {
    const panel = document.getElementById(`po-panel-${key}`);
    if (panel) panel.classList.remove('active');
    if (activePOKey === key) {
        activePOKey = null;
        document.getElementById('stickyBar').style.display = 'none';
    }
}

function cancelActivePO() {
    if (activePOKey) closePOPanel(activePOKey);
}

function submitActivePO() {
    if (!activePOKey) return;
    const form = document.getElementById(`po-form-${activePOKey}`);
    if (form) form.requestSubmit();
}

function confirmPO(form) {
    const qty  = document.getElementById('stickyQty').textContent;
    const cost = document.getElementById('stickyCost').textContent;
    const sup  = document.getElementById('stickySupplier').textContent;
    return confirm(`Create PO for ${sup}?\n\nTotal Qty: ${qty}\nEst. Cost: ${cost}\n\nClick OK to confirm.`);
}

// ── Sticky bar live update ────────────────────────────────────────────
function updateStickyBar() {
    if (!activePOKey) return;
    const form     = document.getElementById(`po-form-${activePOKey}`);
    if (!form) return;
    const qtyInputs  = form.querySelectorAll('.po-qty-input');
    const costInputs = form.querySelectorAll('.po-cost-input');
    let totalQty = 0, totalCost = 0, variants = 0;
    qtyInputs.forEach((q, i) => {
        const qty  = parseInt(q.value) || 0;
        const cost = parseFloat(costInputs[i]?.value) || 0;
        totalQty  += qty;
        totalCost += qty * cost;
        if (qty > 0) variants++;
    });
    document.getElementById('stickyVariants').textContent = variants;
    document.getElementById('stickyQty').textContent      = totalQty.toLocaleString('id-ID');
    document.getElementById('stickyCost').textContent     = 'Rp ' + Math.round(totalCost).toLocaleString('id-ID');
}

// ── Group qty badge update ────────────────────────────────────────────
function updateGroupQty(groupId) {
    const inputs = document.querySelectorAll(`.po-qty-input[data-group="${groupId}"]`);
    let total = 0;
    inputs.forEach(i => total += parseInt(i.value) || 0);
    const badge = document.querySelector(`.po-group-qty[data-group="${groupId}"]`);
    if (badge) badge.textContent = total + ' pcs';
}

// ── Search in demand view ─────────────────────────────────────────────
function searchProducts(input) {
    const key = input.dataset.key;
    const q   = input.value.trim().toLowerCase();
    document.querySelectorAll(`#accordion-${key} .product-group`).forEach(group => {
        const name = group.dataset.productName || '';
        const match = !q || name.includes(q);
        group.classList.toggle('search-hidden', !match);
        if (match && q) {
            // auto-expand matching groups
            const id = group.id.replace('group-', '');
            document.getElementById(`body-${id}`)?.classList.add('open');
            document.querySelector(`[onclick="toggleGroup('${id}')"]`)?.classList.add('open');
        }
    });
}

// ── Search in PO form ─────────────────────────────────────────────────
function searchPOForm(input) {
    const key = input.dataset.formKey;
    const q   = input.value.trim().toLowerCase();
    document.querySelectorAll(`#po-items-${key} .po-product-group`).forEach(group => {
        const name  = group.dataset.productName || '';
        const match = !q || name.includes(q);
        group.classList.toggle('search-hidden', !match);
        if (match && q) {
            const id = group.id.replace('po-group-', '');
            document.getElementById(`body-${id}`)?.classList.add('open');
            document.querySelector(`[onclick="toggleGroup('${id}')"]`)?.classList.add('open');
        }
    });
}
</script>
@endpush