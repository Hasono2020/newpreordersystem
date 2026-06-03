@extends('layouts.app')
@section('title', isset($customer) ? 'Edit Customer' : 'Add Customer')
@section('page-title', isset($customer) ? 'Edit Customer' : 'Add Customer')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-6">
<div class="card">
    <div class="card-body p-4">
        <form method="POST" action="{{ isset($customer) ? route('customers.update', $customer) : route('customers.store') }}">
            @csrf
            @if(isset($customer)) @method('PUT') @endif

            <div class="mb-3">
                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                    value="{{ old('name', $customer->name ?? '') }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Phone / WhatsApp</label>
                <input type="text" name="phone" class="form-control" value="{{ old('phone', $customer->phone ?? '') }}">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Customer Type <span class="text-danger">*</span></label>
                <select name="type" class="form-select @error('type') is-invalid @enderror">
                    <option value="customer" {{ old('type', $customer->type ?? 'customer') == 'customer' ? 'selected' : '' }}>Customer</option>
                    <option value="reseller" {{ old('type', $customer->type ?? '') == 'reseller' ? 'selected' : '' }}>Reseller</option>
                    <option value="selected_customer" {{ old('type', $customer->type ?? '') == 'selected_customer' ? 'selected' : '' }}>Selected Customer</option>
                </select>
                @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Address</label>
                <textarea name="address" class="form-control" rows="2">{{ old('address', $customer->address ?? '') }}</textarea>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Notes</label>
                <textarea name="notes" class="form-control" rows="2">{{ old('notes', $customer->notes ?? '') }}</textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ isset($customer) ? 'Update' : 'Add Customer' }}</button>
                <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
