@extends('layouts.app')
@section('title', 'Record Payment')
@section('page-title', 'Record Payment')

@section('content')

<div class="d-flex gap-2 align-items-center mb-3">
    <a href="{{ session('list_url.payments', route('payments.index', ['trip_id' => $trip->id])) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    <h5 class="mb-0 fw-semibold">Record Payment — {{ $customer->name }}</h5>
</div>

@if(session('error'))
<div class="alert alert-danger">{{ session('error') }}</div>
@endif

@if($orders->isEmpty())
    <div class="alert alert-info">This customer has no unpaid orders in <strong>{{ $trip->name }}</strong>.</div>
@else
<form method="POST" action="{{ route('payments.store') }}" id="paymentForm">
    @csrf
    <input type="hidden" name="customer_id" value="{{ $customer->id }}">
    <input type="hidden" name="trip_id" value="{{ $trip->id }}">

    <div class="row g-3">
        {{-- Left: payment details --}}
        <div class="col-lg-4">
            <div class="card p-3">
                <div class="fw-semibold mb-3">Payment Details</div>

                <label class="form-label small fw-semibold">Amount Received (Rp) <span class="text-danger">*</span></label>
                <input type="number" id="amountReceived" class="form-control mb-1" placeholder="0" step="1" min="0">
                <div class="form-text mb-3">Total balance due: <strong>Rp {{ number_format($totalDue, 0, ',', '.') }}</strong></div>
                @if($strandedCredit > 0)
                    <div class="alert alert-warning py-2 px-3 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        This customer has <strong>Rp {{ number_format($strandedCredit, 0, ',', '.') }}</strong> in credit
                        sitting on an already-overpaid order in this trip, not yet moved to cover the orders below.
                        The total above already accounts for it, but the orders listed still individually show
                        their full remaining balance until that credit is reallocated
                        (<code>php artisan payments:reallocate-credit</code>) or applied manually.
                    </div>
                @endif

                <div class="d-flex gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary flex-fill" id="autoAllocateBtn">
                        <i class="bi bi-magic me-1"></i>Auto-allocate
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="payFullBtn" title="Fill full balance">
                        Full
                    </button>
                </div>

                <label class="form-label small fw-semibold">Date <span class="text-danger">*</span></label>
                <input type="date" name="paid_at" class="form-control mb-3" value="{{ now()->format('Y-m-d') }}" required>

                <input type="hidden" name="method" value="transfer">

                <label class="form-label small fw-semibold">Reference</label>
                <input type="text" name="reference" class="form-control mb-3" placeholder="Transfer ref / proof note">

                <label class="form-label small fw-semibold">Notes</label>
                <textarea name="notes" class="form-control mb-3" rows="2" placeholder="Optional"></textarea>

                <div class="alert alert-secondary py-2 small mb-0">
                    Allocated: <strong id="allocatedSum">Rp 0</strong><br>
                    Unallocated: <strong id="unallocatedSum">Rp 0</strong>
                </div>
            </div>
        </div>

        {{-- Right: per-order allocation --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-white py-3 fw-semibold">Allocate to Orders (oldest first)</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Date</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Already Paid</th>
                                <th class="text-end">Balance</th>
                                <th class="text-end" style="width:160px;">Allocate (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($orders as $i => $order)
                            @php $bal = max(0, $order->total_amount - $order->deposit_paid); @endphp
                            <tr>
                                <td class="font-monospace small">{{ $order->order_number }}</td>
                                <td class="small text-muted">{{ $order->ordered_at?->format('d M Y') }}</td>
                                <td class="text-end small">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td>
                                <td class="text-end small text-success">Rp {{ number_format($order->deposit_paid, 0, ',', '.') }}</td>
                                <td class="text-end fw-semibold text-danger">Rp {{ number_format($bal, 0, ',', '.') }}</td>
                                <td class="text-end">
                                    <input type="hidden" name="allocations[{{ $i }}][order_id]" value="{{ $order->id }}">
                                    <input type="number" name="allocations[{{ $i }}][amount]"
                                        class="form-control form-control-sm text-end alloc-input"
                                        data-balance="{{ $bal }}" value="0" min="0" max="{{ $bal }}" step="1">
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Adjust any amount manually if needed.</span>
                    <button type="submit" class="btn btn-success" id="savePaymentBtn">
                        <i class="bi bi-check-circle me-1"></i>Record Payment
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
const allocInputs = [...document.querySelectorAll('.alloc-input')];
const amountReceived = document.getElementById('amountReceived');

function fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); }

function recalcSums() {
    const allocated = allocInputs.reduce((s, el) => s + (parseFloat(el.value) || 0), 0);
    const received  = parseFloat(amountReceived.value) || allocated;
    document.getElementById('allocatedSum').textContent = fmt(allocated);
    document.getElementById('unallocatedSum').textContent = fmt(Math.max(0, received - allocated));
}

// Auto-allocate: fill oldest orders first up to the amount received
function autoAllocate() {
    let remaining = parseFloat(amountReceived.value) || 0;
    allocInputs.forEach(el => {
        const bal = parseFloat(el.dataset.balance) || 0;
        const pay = Math.min(remaining, bal);
        el.value = pay > 0 ? Math.round(pay) : 0;
        remaining -= pay;
    });
    recalcSums();
}

document.getElementById('autoAllocateBtn').addEventListener('click', autoAllocate);

// "Full" = the true net balance due (computed server-side across ALL of
// this customer's orders, including any overpaid one not shown below —
// summing only the visible rows' balances would miss credit stranded
// there and overstate what's actually still owed).
const totalDueNet = {{ (float) $totalDue }};
document.getElementById('payFullBtn').addEventListener('click', () => {
    amountReceived.value = Math.round(totalDueNet);
    autoAllocate();
});

amountReceived.addEventListener('input', recalcSums);
allocInputs.forEach(el => el.addEventListener('input', recalcSums));

// Guard: don't let an allocation exceed that order's balance
allocInputs.forEach(el => el.addEventListener('change', () => {
    const bal = parseFloat(el.dataset.balance) || 0;
    if ((parseFloat(el.value) || 0) > bal) el.value = Math.round(bal);
    recalcSums();
}));

document.getElementById('paymentForm').addEventListener('submit', function (e) {
    const allocated = allocInputs.reduce((s, el) => s + (parseFloat(el.value) || 0), 0);
    if (allocated <= 0) {
        e.preventDefault();
        alert('Enter at least one allocation amount before saving.');
        return;
    }
    // Use showExport-style overlay (no _processingActive flag) so the
    // browser beforeunload guard doesn't fire on normal form navigation.
    const ov = document.getElementById('processingOverlay');
    document.getElementById('processingMsg').textContent = 'Recording payment…';
    ov.style.display = 'flex';
});

recalcSums();
</script>
@endpush
@endif

@endsection