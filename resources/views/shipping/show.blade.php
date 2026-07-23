@extends('layouts.app')
@section('title', $shipping->name)
@section('page-title', $shipping->name)

@section('content')
<div class="d-flex gap-2 mb-3 align-items-center">
    <a href="{{ route('shipping.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    @if(auth()->user()->hasPermission('shipping.edit'))
    <a href="{{ route('shipping.edit', $shipping) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-pencil me-1"></i>Edit
    </a>
    @endif
    <h5 class="mb-0 ms-2 fw-bold">{{ $shipping->name }}</h5>
    <span class="badge {{ $shipping->is_active ? 'bg-success' : 'bg-secondary' }} ms-1">
        {{ $shipping->is_active ? 'Active' : 'Inactive' }}
    </span>
</div>

<div class="row g-3">
    {{-- Info card --}}
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">
                <i class="bi bi-truck me-2"></i>Area Details
            </div>
            <div class="card-body p-0">
                <table class="table table-borderless mb-0 small">
                    <tr>
                        <td class="text-muted ps-3" style="width:45%">Area Name</td>
                        <td class="fw-semibold">{{ $shipping->name }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Province</td>
                        <td>{{ $shipping->province ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Pricing</td>
                        <td class="fw-semibold text-primary">
                            @if($shipping->isFlatFee())
                                Flat fee: Rp {{ number_format($shipping->flat_fee, 0, ',', '.') }}
                                @if($shipping->flat_fee_subsidy_cap)
                                    <div class="small text-muted fw-normal">Subsidy cap: Rp {{ number_format($shipping->flat_fee_subsidy_cap, 0, ',', '.') }}</div>
                                @endif
                            @else
                                Rp {{ number_format($shipping->price_per_kg, 0, ',', '.') }}/kg
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Status</td>
                        <td>
                            <span class="badge {{ $shipping->is_active ? 'bg-success' : 'bg-secondary' }}">
                                {{ $shipping->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Notes</td>
                        <td>{{ $shipping->notes ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Customers</td>
                        <td>{{ $customerCount }} customers use this area</td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-3">Orders</td>
                        <td>{{ $orderCount }} orders shipped here</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    {{-- Shipping fee reference --}}
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">
                <i class="bi bi-calculator me-2"></i>Shipping Fee Reference
            </div>
            <div class="card-body p-0">
                @if($shipping->isFlatFee())
                    <div class="p-3 text-center">
                        <div class="display-6 fw-bold text-success">Rp {{ number_format($shipping->flat_fee, 0, ',', '.') }}</div>
                        <div class="text-muted mt-1">Fixed fee — same for all weights</div>
                        @if($shipping->flat_fee_subsidy_cap)
                            <div class="mt-2 small badge bg-light text-dark border">
                                Promo subsidy capped at Rp {{ number_format($shipping->flat_fee_subsidy_cap, 0, ',', '.') }}
                            </div>
                        @endif
                    </div>
                @else
                    <table class="table table-hover mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Weight</th>
                                <th>Charged as</th>
                                <th class="text-end pe-3">Fee</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($samples as $grams)
                            @php
                                $kg  = \App\Models\ShippingArea::calcChargeableKg($grams);
                                $fee = $shipping->calcShippingFee($grams);
                            @endphp
                            <tr>
                                <td class="ps-3">{{ number_format($grams) }} g</td>
                                <td class="text-muted">{{ number_format($kg, 0) }} kg</td>
                                <td class="text-end pe-3 fw-semibold">Rp {{ number_format($fee, 0, ',', '.') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    {{-- Quick calculator --}}
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">
                <i class="bi bi-input-cursor me-2"></i>Quick Calculator
            </div>
            <div class="card-body">
                @if($shipping->isFlatFee())
                    <div class="p-3 bg-light rounded text-center">
                        <div class="small text-muted mb-1">Shipping fee to <strong>{{ $shipping->name }}</strong></div>
                        <div class="fs-4 fw-bold text-success">Rp {{ number_format($shipping->flat_fee, 0, ',', '.') }}</div>
                        <div class="small text-muted mt-1">Same for any weight</div>
                    </div>
                    <div class="form-text mt-2">
                        This area uses a <strong>flat fee</strong> — weight does not affect the shipping charge.
                    </div>
                @else
                    <label class="form-label small fw-semibold">Enter weight (grams):</label>
                    <div class="input-group mb-2">
                        <input type="number" id="calcInput" class="form-control" placeholder="e.g. 2500" min="1" oninput="calcFee()">
                        <span class="input-group-text">g</span>
                    </div>
                    <div id="calcResult" class="p-3 bg-light rounded text-center" style="display:none;">
                        <div class="small text-muted">Shipping fee to <strong>{{ $shipping->name }}</strong></div>
                        <div class="fs-4 fw-bold text-primary mt-1" id="calcFeeOut"></div>
                        <div class="small text-muted" id="calcKgOut"></div>
                    </div>
                    <div class="form-text mt-2">
                        Rate: Rp {{ number_format($shipping->price_per_kg, 0, ',', '.') }}/kg<br>
                        Formula: <code>ceil((grams−320)/1000)</code>, min 1 kg
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@if(!$shipping->isFlatFee())
<script>
const pricePerKg = {{ $shipping->price_per_kg }};
function calcFee() {
    const grams  = parseInt(document.getElementById('calcInput').value) || 0;
    const result = document.getElementById('calcResult');
    if (grams <= 0) { result.style.display = 'none'; return; }
    const kg  = grams <= 1320 ? 1 : Math.ceil((grams - 320) / 1000);
    const fee = kg * pricePerKg;
    document.getElementById('calcFeeOut').textContent = 'Rp ' + fee.toLocaleString('id-ID');
    document.getElementById('calcKgOut').textContent  = grams.toLocaleString('id-ID') + 'g → charged as ' + kg + ' kg';
    result.style.display = 'block';
}
</script>
@endif
@endpush