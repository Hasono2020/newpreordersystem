@extends('layouts.app')
@section('title', 'Edit Product')
@section('page-title', 'Edit Product')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card">
<div class="card-body p-4">
<form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data">
    @csrf @method('PUT')

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="{{ old('name', $product->name) }}" required>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Product Code</label>
            <input type="text" name="product_code" class="form-control text-uppercase"
                value="{{ old('product_code', $product->product_code) }}" placeholder="e.g. NA_01">
            <div class="form-text">Prefix used for promo exclusion</div>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Brand</label>
            <input type="text" name="brand" class="form-control" value="{{ old('brand', $product->brand) }}">
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Trip <span class="text-danger">*</span></label>
            <select name="trip_id" class="form-select" required>
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}" {{ old('trip_id', $product->trip_id) == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Base Price (Rp)</label>
            <input type="number" name="price" class="form-control" value="{{ old('price', $product->price) }}" step="1000">
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold">Weight (gram)</label>
            <div class="input-group">
                <input type="number" name="weight_gram" class="form-control" value="{{ old('weight_gram', $product->weight_gram) }}" min="0" step="1">
                <span class="input-group-text">g</span>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" class="form-select">
                @foreach(['active','closed','arrived'] as $s)
                    <option value="{{ $s }}" {{ old('status', $product->status) == $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Description</label>
        <textarea name="description" class="form-control" rows="3">{{ old('description', $product->description) }}</textarea>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Description</label>
        <textarea name="description" class="form-control" rows="3">{{ old('description', $product->description) }}</textarea>
    </div>

    <div class="mb-3">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="excluded_from_promo" value="1" id="excludedFromPromo"
                {{ old('excluded_from_promo', $product->excluded_from_promo) ? 'checked' : '' }}>
            <label class="form-check-label fw-semibold" for="excludedFromPromo">
                Exclude from all promos
            </label>
        </div>
        <div class="form-text">When ticked, this product will <strong>never count</strong> toward any promo discount threshold.</div>
    </div>

    <div class="mb-4">
        <label class="form-label fw-semibold">Replace Image</label>
        @if($product->image)
            <div class="mb-2"><img src="{{ asset('storage/'.$product->image) }}" height="80" class="rounded"></div>
        @endif
        <input type="file" name="image" class="form-control" accept="image/*">
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Update Product</button>
        <a href="{{ route('products.show', $product) }}" class="btn btn-outline-secondary">Cancel</a>
        <form method="POST" action="{{ route('products.destroy', $product) }}" class="ms-auto" onsubmit="return confirm('Delete product?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger">Delete</button>
        </form>
    </div>
</form>
</div>
</div>
</div>
</div>
@endsection
