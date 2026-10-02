@extends('layouts.app')
@section('title', 'Returns & Credit Notes')
@section('page-title', 'Returns & Credit Notes')

@section('content')
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2 flex-wrap">
            <select name="type" class="form-select form-select-sm" style="width:180px;" onchange="this.form.submit()">
                <option value="">All types</option>
                <option value="return" {{ request('type') === 'return' ? 'selected' : '' }}>Sales Return</option>
                <option value="credit_note" {{ request('type') === 'credit_note' ? 'selected' : '' }}>Credit Note</option>
            </select>
            <select name="trip_id" class="form-select form-select-sm" style="width:220px;" onchange="this.form.submit()">
                <option value="">All trips</option>
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}" {{ (string) request('trip_id') === (string) $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
                @endforeach
            </select>
            @if(request('type') || request('trip_id'))
                <a href="{{ route('sales-adjustments.index') }}" class="btn btn-sm btn-link">Clear</a>
            @endif
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Number</th><th>Type</th><th>Order</th><th>Customer</th>
                    <th>Trip</th><th>Date</th><th>Amount</th><th>Reason</th><th>By</th>
                </tr>
            </thead>
            <tbody>
                @forelse($adjustments as $adj)
                <tr class="{{ $adj->isVoided() ? 'text-decoration-line-through text-muted opacity-50' : '' }}">
                    <td><a href="{{ route('sales-adjustments.show', $adj) }}" class="font-monospace">{{ $adj->adjustment_number }}</a></td>
                    <td><span class="badge {{ $adj->isReturn() ? 'bg-danger' : 'bg-warning text-dark' }}">{{ $adj->isReturn() ? 'Return' : 'Credit Note' }}</span></td>
                    <td><a href="{{ route('orders.show', $adj->order) }}" class="font-monospace">{{ $adj->order->order_number }}</a></td>
                    <td>{{ $adj->order->customer->name }}</td>
                    <td>{{ $adj->trip->name }}</td>
                    <td>{{ $adj->created_at->format('d M Y') }}</td>
                    <td class="text-danger fw-semibold">- Rp {{ number_format($adj->amount, 0, ',', '.') }}</td>
                    <td>{{ $adj->reason ?? '—' }}</td>
                    <td>{{ $adj->createdBy->name }}</td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No returns or credit notes yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($adjustments->hasPages())
    <div class="card-footer bg-white">{{ $adjustments->links() }}</div>
    @endif
</div>
@endsection
