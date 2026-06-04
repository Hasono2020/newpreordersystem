@extends('layouts.app')
@section('title', 'Edit PO')
@section('page-title', 'Edit Purchase Order')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">

<form method="POST" action="{{ route('purchasing.update', $purchasing) }}">
@csrf @method('PUT')

<div class="card mb-3">
    <div class="card-header bg-white py-3 fw-semibold">PO Details — {{ $purchasing->po_number }}</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
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
            <div class="col-md-4">
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

<div class="card mb-3">
    <div class="card-header bg-white py-3 fw-semibold">Items</div>
    <div class="card-body">
        @foreach($purchasing->items as $i => $item)
        <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
        <div class="row g-2 align-items-center mb-3 p-2 border rounded">
            <div class="col-md-4 small">
                <strong>{{ $item->product->name }}</strong>
                @if($item->variant)<br><span class="text-muted">{{ $item->variant->label }}</span>@endif
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Qty Ordered</label>
                <input type="number" name="items[{{ $i }}][quantity_ordered]"
                    class="form-control form-control-sm"
                    value="{{ $item->quantity_ordered }}" min="0" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Unit Cost (Rp)</label>
                <input type="number" name="items[{{ $i }}][unit_cost]"
                    class="form-control form-control-sm"
                    value="{{ $item->unit_cost }}" step="1000" min="0" required>
            </div>
            <div class="col-md-2 small text-muted pt-3">
                Line: <strong>Rp {{ number_format($item->line_total, 0, ',', '.') }}</strong>
            </div>
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
</div>
</div>
@endsection
