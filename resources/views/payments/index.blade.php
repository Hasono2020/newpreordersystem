@extends('layouts.app')
@section('title', 'Payments')
@section('page-title', 'Payments')

@section('content')

{{-- Filter bar --}}
<div class="row g-2 mb-3 align-items-end">
    <div class="col">
        <form class="d-flex gap-2 flex-wrap">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="text" name="search" class="form-control form-control-sm" style="width:220px;"
                   placeholder="Name, phone, order, ref, amount…" value="{{ $search ?? '' }}">
            <select name="trip_id" class="form-select form-select-sm" style="width:auto;">
                @foreach($trips as $trip)
                    <option value="{{ $trip->id }}" {{ $tripId == $trip->id ? 'selected' : '' }}>{{ $trip->name }}</option>
                @endforeach
            </select>
            @if($tab === 'log')
            <select name="verification_status" class="form-select form-select-sm" style="width:auto;">
                <option value="">All statuses</option>
                <option value="unverified" {{ ($verificationFilter??'') === 'unverified' ? 'selected' : '' }}>Unverified</option>
                <option value="verified"   {{ ($verificationFilter??'') === 'verified'   ? 'selected' : '' }}>Verified</option>
                <option value="disputed"   {{ ($verificationFilter??'') === 'disputed'   ? 'selected' : '' }}>Disputed</option>
            </select>
            @if(!auth()->user()->isOwnDataOnly())
            <select name="created_by" class="form-select form-select-sm" style="width:auto;">
                <option value="">All Staff</option>
                @foreach($staffList as $staff)
                    <option value="{{ $staff->id }}" {{ ($createdByFilter??'') == $staff->id ? 'selected' : '' }}>
                        {{ $staff->name }}
                    </option>
                @endforeach
            </select>
            @endif
            @endif
            @if($tab === 'outstanding' && !auth()->user()->isOwnDataOnly())
            <select name="created_by" class="form-select form-select-sm" style="width:auto;">
                <option value="">All Staff</option>
                @foreach($staffList as $staff)
                    <option value="{{ $staff->id }}" {{ ($createdByFilter??'') == $staff->id ? 'selected' : '' }}>
                        {{ $staff->name }}
                    </option>
                @endforeach
            </select>
            @endif
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
            @if(!empty($search) || !empty($createdByFilter))
                <a href="{{ route('payments.index', ['trip_id' => $tripId, 'tab' => $tab]) }}" class="btn btn-sm btn-link">Clear</a>
            @endif
            @if(auth()->user()->hasPermission('payments.view'))
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-arrow-down-up me-1"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="min-width:220px;">
                    <li><h6 class="dropdown-header">Export</h6></li>
                    <li>
                        <a onclick="showExport('Preparing payment export…')"
                           class="dropdown-item"
                           href="{{ route('payments.export', ['trip_id' => $tripId]) }}">
                            <i class="bi bi-download me-2 text-success"></i>Export payments as Excel
                        </a>
                    </li>
                </ul>
            </div>
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
            @if(($verificationCounts['unverified'] ?? 0) > 0)
                <span class="badge bg-warning text-dark ms-1">{{ $verificationCounts['unverified'] }}</span>
            @endif
            @if(($verificationCounts['disputed'] ?? 0) > 0)
                <span class="badge bg-danger ms-1">{{ $verificationCounts['disputed'] }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $tab === 'pack' ? 'active' : '' }}"
           href="{{ route('payments.index', ['trip_id' => $tripId, 'tab' => 'pack', 'search' => $search ?? '']) }}">
            <i class="bi bi-box-seam me-1"></i>Ready to Pack
            @if(($readyCount ?? 0) > 0)
                <span class="badge bg-success ms-1">{{ $readyCount }}</span>
            @endif
        </a>
    </li>
</ul>

@if($tab === 'outstanding')
    @if($overpaid->isNotEmpty())
    <div class="card mb-3 border-warning">
        <div class="card-header bg-warning bg-opacity-10 fw-semibold">
            <i class="bi bi-exclamation-triangle me-1 text-warning"></i>
            Overpaid — these customers are owed a refund or have credit ({{ $overpaid->count() }})
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Customer</th>
                        <th class="text-end">Ordered</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Credit / Refund Due</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($overpaid as $oc)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $oc->customer_name }}</div>
                            <div class="small text-muted">{{ $oc->customer_phone }}</div>
                        </td>
                        <td class="text-end">Rp {{ number_format($oc->total_ordered, 0, ',', '.') }}</td>
                        <td class="text-end">Rp {{ number_format($oc->total_paid, 0, ',', '.') }}</td>
                        <td class="text-end fw-semibold text-warning">Rp {{ number_format($oc->credit, 0, ',', '.') }}</td>
                        <td class="text-end">
                            <a href="{{ route('customers.show', $oc->customer_id) }}" class="btn btn-sm btn-outline-secondary">View orders</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            To resolve: open the customer's order and <strong>Void the duplicate payment</strong>, or record a <strong>refund</strong>. Credit can also be left for their next order.
        </div>
    </div>
    @endif
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Customer</th>
                        <th class="text-end">Orders</th>
                        <th class="text-end">Total Ordered</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Balance Due</th>
                        <th class="text-center">Payments</th>
                        <th>Created By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($outstanding as $row)
                    <tr>
                        <td class="fw-semibold">{{ $row->customer_name }}</td>
                        <td class="text-end">{{ $row->order_count }}</td>
                        <td class="text-end">Rp {{ number_format($row->total_ordered, 0, ',', '.') }}</td>
                        <td class="text-end text-success">Rp {{ number_format($row->total_paid, 0, ',', '.') }}</td>
                        <td class="text-end fw-semibold text-danger">Rp {{ number_format($row->balance_due, 0, ',', '.') }}</td>
                        <td class="text-center small">
                            @php
                                $payTotal      = (int)($row->pay_total ?? 0);
                                $payVerified   = (int)($row->pay_verified ?? 0);
                                $payUnverified = (int)($row->pay_unverified ?? 0);
                                $payDisputed   = (int)($row->pay_disputed ?? 0);
                            @endphp
                            @if($payTotal === 0)
                                <span class="badge bg-light text-dark border" title="No active payments recorded yet">No payments</span>
                            @else
                                {{-- Every row here still owes money (balance_due > 0), so never say 'fully settled'. --}}
                                @if($payUnverified === 0 && $payDisputed === 0)
                                    {{-- All existing payments checked, but balance remains --}}
                                    <span class="badge bg-info text-dark" title="The {{ $payVerified }} payment(s) so far are verified — but a balance is still owed">
                                        <i class="bi bi-check2 me-1"></i>{{ $payVerified }} verified · balance owed
                                    </span>
                                @else
                                    <span class="text-muted">{{ $payVerified }}/{{ $payTotal }} verified</span>
                                    @if($payUnverified > 0)
                                        <span class="badge bg-warning text-dark ms-1" title="{{ $payUnverified }} payment(s) waiting for finance to verify">{{ $payUnverified }} unverified</span>
                                    @endif
                                    @if($payDisputed > 0)
                                        <span class="badge bg-danger ms-1" title="{{ $payDisputed }} disputed payment(s)">{{ $payDisputed }} disputed</span>
                                    @endif
                                @endif
                            @endif
                        </td>
                        <td class="small text-muted">
                            @if(($row->creator_count ?? 0) > 1)
                                Multiple
                            @else
                                {{ $row->creator_name ?? '—' }}
                            @endif
                        </td>
                        <td class="text-end">
                            @if(auth()->user()->hasPermission('payments.record'))
                            <a href="{{ route('payments.create', ['customer' => $row->customer_id, 'trip_id' => $tripId]) }}"
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
        @if($outstanding && method_exists($outstanding, 'hasPages') && $outstanding->hasPages())
        <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
            <span class="small text-muted">{{ $outstanding->total() }} customer(s) with balance due</span>
            <div>{{ $outstanding->links() }}</div>
        </div>
        @endif
    </div>

@elseif($tab === 'log')
    {{-- Verification summary bar (finance/admin only) --}}
    @if(auth()->user()->hasPermission('payments.verify'))
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card text-center py-2">
                <div class="small text-muted">Unverified</div>
                <div class="fw-semibold text-warning fs-5">{{ $verificationCounts['unverified'] ?? 0 }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center py-2">
                <div class="small text-muted">Verified</div>
                <div class="fw-semibold text-success fs-5">{{ $verificationCounts['verified'] ?? 0 }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center py-2">
                <div class="small text-muted">Disputed</div>
                <div class="fw-semibold text-danger fs-5">{{ $verificationCounts['disputed'] ?? 0 }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center py-2">
                <div class="small text-muted">Total Verified (Rp)</div>
                <div class="fw-semibold fs-6">{{ number_format($verificationCounts['verified_amount'] ?? 0, 0, ',', '.') }}</div>
            </div>
        </div>
    </div>
    @endif

    {{-- Payment log --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Order</th>
                        <th class="text-end">Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Order By</th>
                        <th>Recorded By</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @php $shownBatches = []; @endphp
                    @forelse($log as $payment)
                    @php
                        $rowClass = $payment->isDisputed() ? 'table-danger' : ($payment->isVerified() ? 'table-success bg-opacity-25' : '');
                        $bid  = $payment->batch_id;
                        $meta = $bid ? ($batchMeta[$bid] ?? null) : null;
                        $showBanner = $bid && $meta && $meta['count'] > 1 && !in_array($bid, $shownBatches);
                        if ($showBanner) $shownBatches[] = $bid;
                    @endphp
                    @if($showBanner)
                    <tr class="table-info">
                        <td colspan="9" class="small py-2">
                            <i class="bi bi-link-45deg me-1"></i>
                            <strong>One transfer — Rp {{ number_format($meta['total'], 0, ',', '.') }}</strong>
                            split across {{ $meta['count'] }} orders for {{ $payment->order->customer->name ?? 'this customer' }}.
                            Verify or void the whole group together.
                        </td>
                    </tr>
                    @endif
                    <tr class="{{ $rowClass }} {{ $payment->isVoided() ? 'text-muted' : '' }}">
                        <td class="small">{{ $payment->paid_at?->format('d M Y') }}</td>
                        <td>{{ $payment->order->customer->name ?? '—' }}</td>
                        <td class="font-monospace small">{{ $payment->order->order_number ?? '—' }}</td>
                        <td class="text-end {{ $payment->type === 'refund' ? 'text-danger' : '' }}">
                            {{ $payment->type === 'refund' ? '-' : '' }}Rp {{ number_format($payment->amount, 0, ',', '.') }}
                            <button type="button" class="btn btn-link btn-sm p-0 ms-1 copy-amount" style="font-size:.7rem;text-decoration:none;"
                                    data-amount="{{ number_format($payment->amount, 0, ',', '.') }}"
                                    title="Copy amount ({{ number_format($payment->amount, 0, ',', '.') }}) to match your bank statement in Excel">
                                <i class="bi bi-clipboard"></i>
                            </button>
                            @if($payment->isVoided())<span class="badge bg-secondary ms-1">Voided</span>@endif
                            @if($bid && $meta && $meta['count'] > 1)
                                <span class="badge bg-info-subtle text-info-emphasis ms-1" style="font-size:.62rem;" title="Part of a Rp {{ number_format($meta['total'], 0, ',', '.') }} transfer across {{ $meta['count'] }} orders">batch</span>
                            @endif
                        </td>
                        <td class="small">{{ ucfirst($payment->method ?? '—') }}</td>
                        <td class="small text-muted">{{ $payment->reference ?? '—' }}</td>
                        <td class="small text-muted">{{ $payment->order->createdBy->name ?? '—' }}</td>
                        <td class="small text-muted">{{ $payment->recordedBy->name ?? '—' }}</td>
                        <td>
                            @if($payment->isVoided())
                                <span class="badge bg-secondary">Voided</span>
                            @elseif($payment->isVerified())
                                <span class="badge bg-success">
                                    <i class="bi bi-check-circle me-1"></i>Verified
                                </span>
                                <div class="small text-muted" style="font-size:.7rem">by {{ $payment->verifiedBy->name ?? '?' }} · {{ $payment->verified_at?->format('d M') }}</div>
                            @elseif($payment->isDisputed())
                                <span class="badge bg-danger">
                                    <i class="bi bi-exclamation-triangle me-1"></i>Disputed
                                </span>
                                <div class="small text-danger" style="font-size:.7rem" title="{{ $payment->dispute_note }}">
                                    {{ \Str::limit($payment->dispute_note, 40) }}
                                </div>
                            @else
                                <span class="badge bg-warning text-dark">Unverified</span>
                            @endif
                        </td>
                        <td class="text-end" style="white-space:nowrap">
                            {{-- Verify/Dispute actions for finance --}}
                            @if(!$payment->isVoided() && auth()->user()->hasPermission('payments.verify') && !$payment->isVerified())
                                @php $verifyRoute = $payment->batch_id ? route('payments.batch.verify', $payment->batch_id) : route('payments.verify', $payment); @endphp
                                <form method="POST" action="{{ $verifyRoute }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2"
                                        title="{{ $payment->batch_id ? 'Verify all payments in this batch' : 'Verify this payment' }}">
                                        <i class="bi bi-check-lg"></i> Verify{{ $payment->batch_id ? ' batch' : '' }}
                                    </button>
                                </form>
                                @if(!$payment->isDisputed())
                                <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2 ms-1"
                                    onclick="showDisputeModal({{ $payment->id }}, '{{ addslashes($payment->order->customer->name ?? '') }}', {{ $payment->amount }})">
                                    <i class="bi bi-exclamation-triangle"></i> Dispute
                                </button>
                                @endif
                            @endif
                            {{-- Void button --}}
                            @if(!$payment->isVoided() && auth()->user()->hasPermission('payments.void'))
                            @php $voidRoute = $payment->batch_id ? "batch/{$payment->batch_id}" : $payment->id; @endphp
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
                                onclick="showVoidModal('{{ $voidRoute }}', {{ $payment->amount }}, {{ $payment->batch_id ? 'true' : 'false' }}, {{ $payment->isVerified() ? 'true' : 'false' }})">
                                Void
                            </button>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($log && method_exists($log, 'hasPages') && $log->hasPages())
        <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
            <span class="small text-muted">{{ $log->total() }} payment(s)</span>
            <div>{{ $log->links() }}</div>
        </div>
        @endif
    </div>
@elseif($tab === 'pack')
    <div class="card">
        <div class="card-body p-0">
            @if(!$tripId)
                <div class="p-4 text-center text-muted">Select a trip to see customers ready to pack.</div>
            @elseif($readyToPack->isEmpty())
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-inbox d-block mb-2" style="font-size:1.5rem;"></i>
                    No customers ready to pack yet — orders must be fully paid and all payments verified.
                </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Customer</th>
                            <th class="text-center">Orders</th>
                            <th class="text-end">Total</th>
                            <th class="text-center">Printed</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($readyToPack as $row)
                        <tr class="{{ $row->printed_at ? 'table-success bg-opacity-25' : '' }}">
                            <td>
                                <div class="fw-semibold">{{ $row->customer_name }}</div>
                                <div class="small text-muted">{{ $row->customer_phone }}</div>
                            </td>
                            <td class="text-center">{{ $row->order_count }}</td>
                            <td class="text-end fw-semibold">Rp {{ number_format($row->total_amount, 0, ',', '.') }}</td>
                            <td class="text-center">
                                @if($row->printed_at)
                                    <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Printed</span>
                                    <div class="small text-muted" style="font-size:.7rem;">{{ \Carbon\Carbon::parse($row->printed_at)->format('d M H:i') }}</div>
                                @else
                                    <span class="badge bg-light text-dark border">Not yet</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('orders.combined-invoice', $row->customer_id) }}?trip_id={{ $tripId }}"
                                   target="_blank" class="btn btn-sm btn-outline-primary me-1">
                                    <i class="bi bi-printer me-1"></i>Print Invoice
                                </a>
                                <a href="{{ route('orders.combined-invoice', $row->customer_id) }}?trip_id={{ $tripId }}&autoprint=1"
                                   target="_blank" class="btn btn-sm btn-outline-dark me-1"
                                   title="Opens the invoice and starts the Save-as-PDF dialog automatically">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>Save PDF
                                </a>
                                <form method="POST" action="{{ route('payments.mark-printed') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="customer_id" value="{{ $row->customer_id }}">
                                    <input type="hidden" name="trip_id" value="{{ $tripId }}">
                                    <button type="submit" class="btn btn-sm {{ $row->printed_at ? 'btn-outline-secondary' : 'btn-success' }}">
                                        @if($row->printed_at)
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Undo
                                        @else
                                            <i class="bi bi-check2-square me-1"></i>Mark Printed
                                        @endif
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center p-3">
                <span class="small text-muted">{{ $readyToPack->total() }} customer(s) ready to pack</span>
                <div>{{ $readyToPack->links() }}</div>
            </div>
            @endif
        </div>
    </div>
@endif

{{-- Dispute Modal --}}
<div class="modal fade" id="disputeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-warning">
                <h5 class="modal-title text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Dispute Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="disputeForm" method="POST">
                @csrf
                <div class="modal-body">
                    <p class="mb-3">Disputing: <strong id="disputeCustomerName"></strong> — <strong id="disputeAmountDisplay"></strong></p>
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-info-circle me-1"></i>
                        The payment will be marked as disputed. Void it and re-record the correct amount after.
                    </div>
                    <label class="form-label fw-semibold">Reason / discrepancy note <span class="text-danger">*</span></label>
                    <textarea name="dispute_note" class="form-control" rows="3"
                        placeholder="e.g. Bank statement shows Rp 200,000 received, not Rp 400,000. Ref TF#2291."
                        required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>Mark as Disputed
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
                    <div id="voidVerifiedWarning" class="alert alert-danger py-2 small" style="display:none;">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        <strong>This payment was already verified by finance.</strong> Only void it if it was a genuine error (refund, duplicate, or chargeback).
                    </div>
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Voiding restores the order balance. The record is kept for audit.
                    </div>
                    <p class="mb-2">Voiding: <strong id="voidAmountDisplay"></strong></p>
                    <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                    <textarea name="void_reason" class="form-control" rows="3" required
                        placeholder="e.g. Duplicate entry — customer only made one transfer."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Void</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function showDisputeModal(paymentId, customerName, amount) {
    const form = document.getElementById('disputeForm');
    form.action = `/payments/${paymentId}/dispute`;
    form.querySelector('textarea').value = '';
    document.getElementById('disputeCustomerName').textContent = customerName;
    document.getElementById('disputeAmountDisplay').textContent = 'Rp ' + Math.round(amount).toLocaleString('id-ID');
    new bootstrap.Modal(document.getElementById('disputeModal')).show();
}

function showVoidModal(routeSuffix, amount, isBatch, isVerified) {
    const form = document.getElementById('voidPaymentForm');
    form.action = `/payments/${routeSuffix}/void`;
    form.querySelector('textarea').value = '';
    document.getElementById('voidAmountDisplay').textContent = 'Rp ' + Math.round(amount).toLocaleString('id-ID');
    // Extra warning when voiding an already-verified payment
    const warn = document.getElementById('voidVerifiedWarning');
    if (warn) warn.style.display = isVerified ? 'block' : 'none';
    new bootstrap.Modal(document.getElementById('voidPaymentModal')).show();
}

// Copy a payment amount (formatted like the bank statement: 200.011) to the clipboard
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.copy-amount');
    if (!btn) return;
    const amount = btn.dataset.amount; // e.g. "200.011"
    const done = () => {
        const icon = btn.querySelector('i');
        const old = icon ? icon.className : '';
        if (icon) icon.className = 'bi bi-check-lg text-success';
        btn.title = 'Copied!';
        setTimeout(() => { if (icon) icon.className = old; btn.title = 'Copy amount'; }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(amount).then(done).catch(() => fallbackCopy(amount, done));
    } else {
        fallbackCopy(amount, done);
    }
});
function fallbackCopy(text, cb) {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); cb(); } catch (e) {}
    document.body.removeChild(ta);
}
</script>
@endpush

@endsection