@extends('layouts.app')
@section('title', 'Payments')
@section('page-title', 'Payments')

@section('content')

{{-- Filter bar (matches Orders / Customers / etc.) --}}
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2 flex-wrap">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="text" name="search" class="form-control form-control-sm" style="width:220px;"
                   placeholder="Name, phone, order, ref…" value="{{ $search ?? '' }}">
            <select name="trip_id" class="form-select form-select-sm" style="width:auto;">
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}" {{ $tripId == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
            <a href="{{ route('payments.export', ['trip_id' => $tripId]) }}"
               class="btn btn-sm btn-outline-success ms-1"
               onclick="showExport('Preparing export…')">
                <i class="bi bi-file-earmark-excel me-1"></i>Export
            </a>
            @if(!empty($search))
                <a href="{{ route('payments.index', ['trip_id' => $tripId, 'tab' => $tab]) }}" class="btn btn-sm btn-link">Clear</a>
            @endif
        </form>
    </div>

</div>

{{-- Tabs --}}
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'outstanding' ? 'active' : '' }}"
           href="{{ route('payments.index', ['trip_id' => $tripId, 'tab' => 'outstanding', 'search' => $search ?? '']) }}">
            <i class="bi bi-cash-stack me-1"></i>Outstanding Balances
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'log' ? 'active' : '' }}"
           href="{{ route('payments.index', ['trip_id' => $tripId, 'tab' => 'log', 'search' => $search ?? '']) }}">
            <i class="bi bi-clock-history me-1"></i>Payment Log
        </a>
    </li>
</ul>

@if($tab === 'outstanding')
    {{-- Outstanding balances --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 responsive-cards">
                <thead class="table-light">
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
                               class="btn btn-sm btn-outline-primary">
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
    {{-- Payment log --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 responsive-cards">
                <thead class="table-light">
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
                            @if(!$payment->isVoided() && auth()->user()->hasPermission('payments.void'))
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2"
                                onclick="showVoidModal(
                                    {{ $payment->batch_id ? "'batch/{$payment->batch_id}'" : "'{$payment->id}'" }},
                                    {{ $payment->amount }},
                                    {{ $payment->batch_id ? 'true' : 'false' }})">
                                Void
                            </button>
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
        <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
            <span class="small text-muted">{{ $log->total() }} payment(s)</span>
            <div>{{ $log->links() }}</div>
        </div>
        @endif
    </div>
@endif

{{-- Void Payment Modal --}}
<div class="modal fade" id="voidPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-danger">
                <h5 class="modal-title text-danger"><i class="bi bi-x-circle me-2"></i>Void Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="voidPaymentForm" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>Voiding does not delete the record.</strong> It marks the payment as invalid and restores the affected order balances. A full audit trail is kept.
                    </div>
                    <p class="mb-2">You are voiding: <strong id="voidAmountDisplay"></strong></p>
                    <label class="form-label fw-semibold">Reason for voiding <span class="text-danger">*</span></label>
                    <textarea name="void_reason" class="form-control" rows="3"
                        placeholder="e.g. Duplicate entry — customer only made one transfer."
                        required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle me-1"></i>Void This Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function showVoidModal(routeSuffix, amount, isBatch) {
    const form = document.getElementById('voidPaymentForm');
    // isBatch=true  → /payments/batch/{batchId}/void
    // isBatch=false → /payments/{id}/void
    form.action = `/payments/${routeSuffix}/void`;
    form.querySelector('textarea').value = '';
    document.getElementById('voidAmountDisplay').textContent =
        'Rp ' + Math.round(amount).toLocaleString('id-ID');
    new bootstrap.Modal(document.getElementById('voidPaymentModal')).show();
}
</script>
@endpush

@endsection