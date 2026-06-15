@extends('layouts.app')
@section('title', isset($promo) ? 'Edit Promo Rule' : 'New Promo Rule')
@section('page-title', isset($promo) ? 'Edit Promo Rule' : 'New Promo Rule')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
<div class="card-body p-4">
<form method="POST" action="{{ isset($promo) ? route('promos.update', $promo) : route('promos.store') }}">
    @csrf
    @if(isset($promo)) @method('PUT') @endif

    <div class="mb-3">
        <label class="form-label fw-semibold">Rule Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control" value="{{ old('name', $promo->name ?? '') }}" required placeholder="e.g. Free Shipping 3+ items">
    </div>
    <div class="mb-3">
        <label class="form-label fw-semibold">Description</label>
        <textarea name="description" class="form-control" rows="2">{{ old('description', $promo->description ?? '') }}</textarea>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Minimum Items <span class="text-danger">*</span></label>
            <input type="number" name="min_items" class="form-control" value="{{ old('min_items', $promo->min_items ?? 1) }}" min="1" required>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Flat Discount (Rp)</label>
            <input type="number" name="discount_flat" class="form-control" value="{{ old('discount_flat', $promo->discount_flat ?? 0) }}" step="1" min="0">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Discount per Item (Rp)</label>
            <input type="number" name="discount_per_item" class="form-control" value="{{ old('discount_per_item', $promo->discount_per_item ?? 0) }}" step="1" min="0">
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Max Shipping Subsidy (Rp)</label>
            <input type="number" name="max_shipping_subsidy" class="form-control" value="{{ old('max_shipping_subsidy', $promo->max_shipping_subsidy ?? 0) }}" step="1" min="0">
            <div class="form-text">Max free shipping given</div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Restrict to Trip</label>
            <select name="trip_id" class="form-select">
                <option value="">Global (all trips)</option>
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}" {{ old('trip_id', $promo->trip_id ?? '') == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Active</label>
            <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ old('is_active', $promo->is_active ?? true) ? 'checked' : '' }}>
                <label class="form-check-label">Enabled</label>
            </div>
        </div>
    </div>

    <div class="mb-4">
        <label class="form-label fw-semibold">Eligible Customer Types</label>
        <div class="form-text mb-2">Leave all unchecked to apply to all customer types.</div>
        @php $currentTypes = old('eligible_customer_types', $promo->eligible_customer_types ?? []); @endphp
        @foreach(['customer' => 'Customer', 'reseller' => 'Reseller', 'selected_customer' => 'Selected Customer'] as $val => $label)
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="eligible_customer_types[]" value="{{ $val }}"
                id="type_{{ $val }}" {{ in_array($val, (array) $currentTypes) ? 'checked' : '' }}>
            <label class="form-check-label" for="type_{{ $val }}">{{ $label }}</label>
        </div>
        @endforeach
    </div>

    <div class="mb-4">
        <label class="form-label fw-semibold">Excluded Product Code Prefixes</label>
        <input type="text" name="excluded_product_codes" class="form-control"
            value="{{ old('excluded_product_codes', implode(', ', $promo->excluded_product_codes ?? [])) }}"
            placeholder="e.g. MZ, NZ, PZ">
        <div class="form-text">
            Comma-separated code prefixes to <strong>exclude from discount</strong>.
            Products with codes like <code>NZ_01</code>, <code>MZ_03</code> will not count toward item total for this promo.
            Leave empty to include all products.
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">{{ isset($promo) ? 'Update' : 'Create Rule' }}</button>
        <a href="{{ route('promos.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
</div>
</div>
</div>
</div>
@endsection