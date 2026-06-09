@extends('layouts.app')
@section('title', $purchasing->po_number)
@section('page-title', 'Purchase Order: '.$purchasing->po_number)

@push('styles')
<style>
/* ── Sticky header bar ───────────────────────── */
.po-topbar {
    position: sticky; top: 0; z-index: 100;
    background: #1e293b; color: #fff;
    padding: .55rem 1rem;
    display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
    box-shadow: 0 2px 10px rgba(0,0,0,.18);
    margin: -.5rem -1.5rem 1.25rem -1.5rem;
}
.po-topbar .tb-stat { text-align:center; padding: 0 .6rem; border-right: 1px solid #334155; }
.po-topbar .tb-stat:last-of-type { border-right: none; }
.po-topbar .tb-stat .v { font-size: .88rem; font-weight: 700; color: #38bdf8; line-height:1.2; }
.po-topbar .tb-stat .l { font-size: .6rem; color: #94a3b8; text-transform: uppercase; letter-spacing:.04em; }
body { padding-bottom: 0; }

/* ── Items table virtual scroll ──────────────── */
#itemsVirtualWrap { max-height: 60vh; overflow-y: auto; }
.items-thead th {
    position: sticky; top: 0; background: #f8fafc; z-index: 5;
    font-size: .78rem; padding: .5rem .75rem;
    border-bottom: 2px solid #e5e7eb;
}
.items-tbody td { font-size: .82rem; padding: .4rem .75rem; vertical-align: middle; }
.qty-input { width: 78px; }

/* ── Page load skeleton ──────────────────────── */
.skeleton { background: linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);
    background-size: 200% 100%; animation: skel 1.2s infinite; border-radius:4px; height:16px; }
@keyframes skel { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
</style>
@endpush

@section('content')

{{-- ── Sticky Top Bar ── --}}
<div class="po-topbar">
    {{-- Back + Status --}}
    <a href="{{ route('purchasing.index', ['trip_id' => $purchasing->trip_id]) }}"
       class="btn btn-sm btn-outline-light py-1 px-2 me-1">
        <i class="bi bi-arrow-left"></i>
    </a>
    <span class="badge {{ match($purchasing->status) {
        'arrived'   => 'bg-success',
        'confirmed' => 'bg-primary',
        'submitted' => 'bg-warning text-dark',
        default     => 'bg-secondary'
    } }} me-1">{{ ucfirst($purchasing->status) }}</span>

    {{-- Stats --}}
    <div class="tb-stat">
        <div class="v">{{ $purchasing->po_number }}</div>
        <div class="l">PO Number</div>
    </div>
    <div class="tb-stat d-none d-md-block">
        <div class="v">{{ $purchasing->trip->name }}</div>
        <div class="l">Trip</div>
    </div>
    <div class="tb-stat d-none d-md-block">
        <div class="v">{{ $purchasing->supplier?->name ?? '—' }}</div>
        <div class="l">Supplier</div>
    </div>
    <div class="tb-stat">
        <div class="v">{{ $purchasing->items->count() }} / {{ number_format($purchasing->items->sum('quantity_ordered')) }}</div>
        <div class="l">Lines / Pcs</div>
    </div>

    {{-- Actions --}}
    <div class="ms-auto d-flex gap-2 align-items-center">
        @if($purchasing->status !== 'arrived')
        <a href="{{ route('purchasing.edit', $purchasing) }}"
           class="btn btn-sm btn-outline-light py-1">
            <i class="bi bi-pencil me-1"></i><span class="d-none d-md-inline">Edit PO</span>
        </a>
        @endif
        <form method="POST" action="{{ route('purchasing.destroy', $purchasing) }}"
            onsubmit="return confirm('Delete this purchase order?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger py-1">
                <i class="bi bi-trash3 me-1"></i><span class="d-none d-md-inline">Delete</span>
            </button>
        </form>
        @if($purchasing->status !== 'arrived')
        <button type="button" class="btn btn-sm btn-success py-1 px-3" onclick="submitArrival()">
            <i class="bi bi-check-circle me-1"></i>Confirm Arrival
        </button>
        @endif
    </div>
</div>

{{-- ── PO Details card ── --}}
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-3 align-items-center">
            <div class="col-6 col-md-3">
                <div class="text-muted small">PO Number</div>
                <div class="font-monospace fw-bold">{{ $purchasing->po_number }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Trip</div>
                <div>{{ $purchasing->trip->name }}</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-muted small">Supplier</div>
                <div>{{ $purchasing->supplier?->name ?? '—' }}</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-muted small">Date</div>
                <div>{{ $purchasing->purchased_at?->format('d M Y') ?? '—' }}</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-muted small">Status</div>
                <span class="badge {{ match($purchasing->status) {
                    'arrived'=>'bg-success','confirmed'=>'bg-primary',
                    'submitted'=>'bg-warning text-dark',default=>'bg-secondary'
                } }}">{{ ucfirst($purchasing->status) }}</span>
            </div>
        </div>
    </div>
</div>

{{-- ── Items ── --}}
<div class="card">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-semibold">Items</span>
            <span class="badge bg-secondary">{{ $purchasing->items->count() }} lines</span>
            <span class="badge bg-light text-dark border">{{ number_format($purchasing->items->sum('quantity_ordered')) }} pcs</span>
        </div>
        <div class="d-flex align-items-center gap-3">
            {{-- Live search --}}
            <input type="text" id="itemSearch" class="form-control form-control-sm" style="width:200px;"
                placeholder="Search product / variant…" oninput="filterItems(this.value)">
            @if($purchasing->status !== 'arrived')
            <span class="small text-muted d-none d-md-block">
                <i class="bi bi-info-circle me-1"></i>Adjust received qty · click <strong>Confirm Arrival</strong>
            </span>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('purchasing.arrival', $purchasing) }}" id="arrivalForm">
        @csrf
        <div id="itemsVirtualWrap">
            <table class="table table-hover mb-0">
                <thead>
                    <tr class="items-thead">
                        <th>#</th>
                        <th>Product</th>
                        <th>Variant</th>
                        <th>Ordered</th>
                        @if($purchasing->status !== 'arrived')
                        <th>Received</th>
                        @else
                        <th>Received</th>
                        @endif
                    </tr>
                </thead>
                <tbody id="itemsBody">
                    {{-- Rendered by JS for performance --}}
                </tbody>
            </table>
        </div>

        {{-- hidden inputs for all items --}}
        <div id="hiddenInputs" style="display:none;">
            @foreach($purchasing->items as $i => $item)
            <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
            @if($purchasing->status !== 'arrived')
            <input type="number" name="items[{{ $i }}][quantity_received]"
                class="qty-hidden" data-index="{{ $i }}"
                value="{{ $item->quantity_received ?: $item->quantity_ordered }}"
                min="0" max="{{ $item->quantity_ordered }}">
            @endif
            @endforeach
        </div>

        @if($purchasing->status !== 'arrived')
        <div class="card-footer bg-white d-flex justify-content-between align-items-center py-3">
            <span class="small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Orders not covered will be marked <strong>Sold Out</strong>.
            </span>
            <button type="button" class="btn btn-success" onclick="submitArrival()">
                <i class="bi bi-check-circle me-1"></i>Confirm Arrival & Allocate
            </button>
        </div>
        @endif
    </form>
</div>

@if($purchasing->status === 'arrived')
<div class="alert alert-success mt-3">
    <i class="bi bi-check-circle-fill me-2"></i>Stock has been received and allocated via FIFO.
</div>
@endif

{{-- Pass items as JSON for fast JS rendering --}}
@php
$itemsJson = $purchasing->items->map(function($item, $i) {
    return [
        'i'        => $i,
        'id'       => $item->id,
        'product'  => $item->product->name,
        'code'     => $item->product->product_code ?? '',
        'variant'  => $item->variant?->label ?? '—',
        'ordered'  => $item->quantity_ordered,
        'received' => $item->quantity_received ?: $item->quantity_ordered,
    ];
})->values()->all();
@endphp
<script>
const ITEMS   = {!! json_encode($itemsJson) !!};
const ARRIVED = {{ $purchasing->status === 'arrived' ? 'true' : 'false' }};
const PAGE_SZ = 100; // render in chunks
let filtered  = ITEMS;
let rendered  = 0;

function renderChunk() {
    const body  = document.getElementById('itemsBody');
    const chunk = filtered.slice(rendered, rendered + PAGE_SZ);
    if (!chunk.length) return;
    const html = chunk.map((r, ci) => {
        const qtyCell = ARRIVED
            ? `<td>${r.received}</td>`
            : `<td><input type="number" class="form-control form-control-sm qty-input qty-vis"
                  data-index="${r.i}" value="${r.received}"
                  min="0" max="${r.ordered}"
                  oninput="syncHidden(${r.i}, this.value)"></td>`;
        return `<tr class="item-row items-tbody" data-product="${r.product.toLowerCase()}" data-variant="${r.variant.toLowerCase()}">
            <td class="text-muted" style="width:40px">${rendered + ci + 1}</td>
            <td><span class="fw-semibold">${escH(r.product)}</span>${r.code ? `<br><span class="font-monospace text-primary" style="font-size:.7rem">${escH(r.code)}</span>` : ''}</td>
            <td>${escH(r.variant)}</td>
            <td>${r.ordered}</td>
            ${qtyCell}
        </tr>`;
    }).join('');
    body.insertAdjacentHTML('beforeend', html);
    rendered += chunk.length;
}

function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function syncHidden(idx, val) {
    const hidden = document.querySelector(`.qty-hidden[data-index="${idx}"]`);
    if (hidden) hidden.value = val;
}

function filterItems(q) {
    q = q.trim().toLowerCase();
    filtered = q ? ITEMS.filter(r => (r.product||'').toLowerCase().includes(q) || (r.variant||'').toLowerCase().includes(q)) : ITEMS;
    const body = document.getElementById('itemsBody');
    body.innerHTML = '';
    rendered = 0;
    renderChunk();
    // re-render remaining
    while (rendered < filtered.length) renderChunk();
}

function submitArrival() {
    if (confirm('Confirm arrival and run FIFO allocation?\n\nThis will update all order item statuses.')) {
        document.getElementById('arrivalForm').submit();
    }
}

// Progressive render on scroll
document.getElementById('itemsVirtualWrap').addEventListener('scroll', function() {
    if (rendered >= filtered.length) return;
    if (this.scrollTop + this.clientHeight >= this.scrollHeight - 100) renderChunk();
});

// Initial render
renderChunk();
</script>

@endsection