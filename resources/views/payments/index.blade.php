@extends('layouts.app')
@section('title', 'Payments')
@section('page-title', 'Payments')

@section('content')

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-1"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- Trip selector + tabs --}}
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center justify-content-between">
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label class="small text-muted mb-0">Trip</label>
        <select name="trip_id" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
            @foreach($trips as $trip)
                <option value="{{ $trip->id }}" {{ $tripId == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
            @endforeach
        </select>
    </form>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'outstanding' ? 'active' : '' }}"
           href="{{ route('payments.index', ['trip_id' => $tripId, 'tab' => 'outstanding']) }}">
            <i class="bi bi-cash-stack me-1"></i>Outstanding Balances
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'log' ? 'active' : '' }}"
           href="{{ route('payments.index', ['trip_id' => $tripId, 'tab' => 'log']) }}">
            <i class="bi bi-clock-history me-1"></i>Payment Log
        </a>
    </li>
</ul>

@if($tab === 'outstanding')
    {{-- ── Outstanding balances ── --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 responsive-cards">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th class="text-end">Orders</th>
                        <th class="text-end">Total Ordered</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Balance Due</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($outstanding as $row)
                    <tr>
                        <td data-label="Customer" class="fw-semibold">{{ $row->customer->name }}</td>
                        <td data-label="Orders" class="text-end">{{ $row->order_count }}</td>
                        <td data-label="Total Ordered" class="text-end">Rp {{ number_format($row->total_ordered, 0, ',', '.') }}</td>
                        <td data-label="Paid" class="text-end text-success">Rp {{ number_format($row->total_paid, 0, ',', '.') }}</td>
                        <td data-label="Balance Due" class="text-end fw-semibold text-danger">Rp {{ number_format($row->balance_due, 0, ',', '.') }}</td>
                        <td class="cell-actions no-label text-end">
                            @if(auth()->user()->hasPermission('payments.record'))
                            <a href="{{ route('payments.create', ['customer' => $row->customer->id, 'trip_id' => $tripId]) }}"
                               class="btn btn-sm btn-primary">
                                <i class="bi bi-plus-lg me-1"></i>Record Payment
                            </a>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No outstanding balances for this trip. 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@else
    {{-- ── Payment log ── --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 responsive-cards">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Order</th>
                        <th class="text-end">Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Recorded By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($log as $payment)
                    <tr class="{{ $payment->isVoided() ? 'text-muted' : '' }}">
                        <td data-label="Date" class="small">{{ $payment->paid_at?->format('d M Y') }}</td>
                        <td data-label="Customer">{{ $payment->order->customer->name ?? '—' }}</td>
                        <td data-label="Order" class="font-monospace small">{{ $payment->order->order_number ?? '—' }}</td>
                        <td data-label="Amount" class="text-end {{ $payment->type === 'refund' ? 'text-danger' : '' }}">
                            {{ $payment->type === 'refund' ? '-' : '' }}Rp {{ number_format($payment->amount, 0, ',', '.') }}
                            @if($payment->isVoided())<span class="badge bg-secondary ms-1">Voided</span>@endif
                        </td>
                        <td data-label="Method" class="small">{{ ucfirst($payment->method ?? '—') }}</td>
                        <td data-label="Reference" class="small text-muted">{{ $payment->reference ?? '—' }}</td>
                        <td data-label="Recorded By" class="small text-muted">{{ $payment->recordedBy->name ?? '—' }}</td>
                        <td class="no-label text-end">
                            @if(!$payment->isVoided() && $payment->batch_id && auth()->user()->hasPermission('payments.void'))
                            <form method="POST" action="{{ route('payments.batch.void', $payment->batch_id) }}"
                                  onsubmit="return confirm('Void this entire payment batch? All orders it covered will have their balances restored.')">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2">Void</button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($log->hasPages())
        <div class="card-footer bg-white">{{ $log->links() }}</div>
        @endif
    </div>
@endif

@endsection