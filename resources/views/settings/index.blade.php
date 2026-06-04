@extends('layouts.app')
@section('title', 'Store Settings')
@section('page-title', 'Store Settings')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold">
        <i class="bi bi-shop me-2"></i>Store Identity
    </div>
    <div class="card-body p-4">
        <form method="POST" action="{{ route('settings.update') }}">
            @csrf @method('PUT')

            <div class="mb-3">
                <label class="form-label fw-semibold">Store Name <span class="text-danger">*</span></label>
                <input type="text" name="store_name" class="form-control @error('store_name') is-invalid @enderror"
                    value="{{ old('store_name', $store_name) }}" required placeholder="e.g. Toko Sari Fashion">
                <div class="form-text">Appears on the invoice header.</div>
                @error('store_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Tagline / Description</label>
                <input type="text" name="store_tagline" class="form-control"
                    value="{{ old('store_tagline', $store_tagline) }}" placeholder="e.g. Overseas Shopping Service">
                <div class="form-text">Short description shown below the store name on invoices.</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Phone / WhatsApp</label>
                <input type="text" name="store_phone" class="form-control"
                    value="{{ old('store_phone', $store_phone) }}" placeholder="e.g. 0812-3456-7890">
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Address</label>
                <textarea name="store_address" class="form-control" rows="2"
                    placeholder="Store address (optional, shown on invoice)">{{ old('store_address', $store_address) }}</textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Save Settings
            </button>
        </form>
    </div>
</div>

<div class="card mt-3 border-0 bg-light">
    <div class="card-body small text-muted">
        <i class="bi bi-eye me-1"></i>Preview — how your store name appears on invoices:
        <div class="mt-2 p-3 bg-white border rounded">
            <div style="font-size:1.2rem;font-weight:800;color:#1e2a3a;">{{ $store_name }}</div>
            <div style="font-size:.75rem;color:#64748b;">{{ $store_tagline }}</div>
        </div>
    </div>
</div>
</div>
</div>
@endsection
