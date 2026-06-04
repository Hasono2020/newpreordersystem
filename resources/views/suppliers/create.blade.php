@extends('layouts.app')
@section('title', isset($supplier) ? 'Edit Supplier' : 'Add Supplier')
@section('page-title', isset($supplier) ? 'Edit Supplier' : 'Add Supplier')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-6">
<div class="card">
<div class="card-body p-4">
<form method="POST" action="{{ isset($supplier) ? route('suppliers.update', $supplier) : route('suppliers.store') }}">
    @csrf
    @if(isset($supplier)) @method('PUT') @endif

    <div class="mb-3">
        <label class="form-label fw-semibold">Supplier Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
            value="{{ old('name', $supplier->name ?? '') }}" required placeholder="e.g. Guangzhou Textile Co.">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Contact Person</label>
            <input type="text" name="contact_person" class="form-control"
                value="{{ old('contact_person', $supplier->contact_person ?? '') }}" placeholder="e.g. Mr. Wang">
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Phone / WeChat / WhatsApp</label>
            <input type="text" name="phone" class="form-control"
                value="{{ old('phone', $supplier->phone ?? '') }}" placeholder="+86 xxx">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Country</label>
        <input type="text" name="country" class="form-control"
            value="{{ old('country', $supplier->country ?? '') }}" placeholder="e.g. China, Korea, Japan"
            list="countryList">
        <datalist id="countryList">
            <option value="China"><option value="Korea"><option value="Japan">
            <option value="Thailand"><option value="Turkey"><option value="India">
        </datalist>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Notes</label>
        <textarea name="notes" class="form-control" rows="3"
            placeholder="Payment terms, lead time, min order qty…">{{ old('notes', $supplier->notes ?? '') }}</textarea>
    </div>

    <div class="mb-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                {{ old('is_active', $supplier->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label fw-semibold" for="isActive">Active</label>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            {{ isset($supplier) ? 'Update Supplier' : 'Add Supplier' }}
        </button>
        <a href="{{ route('suppliers.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
</div>
</div>
</div>
</div>
@endsection
