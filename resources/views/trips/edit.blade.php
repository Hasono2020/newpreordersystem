@extends('layouts.app')
@section('title', isset($trip) ? 'Edit Trip' : 'New Trip')
@section('page-title', isset($trip) ? 'Edit Trip' : 'New Trip')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-body p-4">
        <form method="POST" action="{{ isset($trip) ? route('trips.update', $trip) : route('trips.store') }}">
            @csrf
            @if(isset($trip)) @method('PUT') @endif

            <div class="mb-3">
                <label class="form-label fw-semibold">Trip Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                    value="{{ old('name', $trip->name ?? '') }}" placeholder="e.g. Korea June 2025" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row g-3 mb-3">
                <div class="col">
                    <label class="form-label fw-semibold">Destination</label>
                    <input type="text" name="destination" class="form-control"
                        value="{{ old('destination', $trip->destination ?? '') }}" placeholder="Seoul, Korea">
                </div>
                @if(isset($trip))
                <div class="col">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        @foreach(['open' => 'Open (accepting orders)', 'order_closed' => 'Order Closed (no new orders)', 'purchasing' => 'Purchasing', 'arrived' => 'Arrived', 'closed' => 'Closed'] as $val => $label)
                            <option value="{{ $val }}" {{ old('status', $trip->status ?? 'open') == $val ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @endif
            </div>

            <div class="row g-3 mb-3">
                <div class="col">
                    <label class="form-label fw-semibold">Trip Date</label>
                    <input type="date" name="trip_date" class="form-control"
                        value="{{ old('trip_date', isset($trip) ? $trip->trip_date?->format('Y-m-d') : '') }}">
                </div>
                <div class="col">
                    <label class="form-label fw-semibold">Order Deadline</label>
                    <input type="date" name="order_deadline" class="form-control"
                        value="{{ old('order_deadline', isset($trip) ? $trip->order_deadline?->format('Y-m-d') : '') }}">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Notes</label>
                <textarea name="notes" class="form-control" rows="3">{{ old('notes', $trip->notes ?? '') }}</textarea>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ isset($trip) ? 'Update' : 'Create Trip' }}</button>
                <a href="{{ route('trips.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
