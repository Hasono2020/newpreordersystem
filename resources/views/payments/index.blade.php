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
        <a class="nav-link {{ $tab === 'shipment' ? 'active' : '' }}"
           href="{{ route('payments.index', ['trip_id' => $tripId, 'tab' => 'shipment', 'search' => $search ?? '']) }}">
            <i class="bi bi-box-seam me-1"></i>Shipment
            @if((($toShipCount ?? 0) + ($partialCount ?? 0)) > 0)
                <span class="badge bg-success ms-1" title="Fully paid, with packages still to send">{{ ($toShipCount ?? 0) + ($partialCount ?? 0) }}</span>
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
                        <td class="text-end fw-semibold text-warning">
                            Rp {{ number_format($oc->credit, 0, ',', '.') }}
                            @if($oc->unverified_credit > 0.5)
                                <div class="small fw-normal text-muted">
                                    Rp {{ number_format($oc->verified_credit, 0, ',', '.') }} verified ·
                                    <span class="text-danger">Rp {{ number_format($oc->unverified_credit, 0, ',', '.') }} unverified</span>
                                </div>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="btn-group">
                                <a href="{{ route('customers.show', $oc->customer_id) }}" class="btn btn-sm btn-outline-secondary">View orders</a>
                                @if($oc->defaultOrder && $oc->verified_credit > 0.5)
                                    <a href="{{ route('orders.show', $oc->defaultOrder->id) }}?open_credit_note=1&credit_amount={{ (int) $oc->verified_credit }}"
                                       class="btn btn-sm btn-outline-danger"
                                       title="Defaults to {{ $oc->defaultOrder->order_number }} — the order holding this customer's spare verified credit, since that's where a refund has to come from">
                                        <i class="bi bi-cash-coin me-1"></i>Credit Note
                                    </a>
                                @else
                                    {{-- Nothing verified to refund yet: a payment someone only SAYS was
                                         made must be confirmed against the bank first. --}}
                                    <a href="{{ route('payments.index', ['trip_id' => $tripId, 'tab' => 'log', 'verification_status' => 'unverified', 'search' => $oc->customer_name]) }}"
                                       class="btn btn-sm btn-warning"
                                       title="This credit comes from payments that haven't been verified. A refund can only be issued against verified money.">
                                        <i class="bi bi-shield-check me-1"></i>Verify payment first
                                    </a>
                                @endif
                                @if($oc->orders->count() > 1 && $oc->verified_credit > 0.5)
                                    <button type="button" class="btn btn-sm btn-outline-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" title="Use a different order instead">
                                        <span class="visually-hidden">Choose a different order</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><h6 class="dropdown-header">Use a different order</h6></li>
                                        @foreach($oc->orders as $o)
                                        @php $balance = (float) $o->total_amount - (float) $o->deposit_paid; @endphp
                                        <li>
                                            <a class="dropdown-item small @if($oc->defaultOrder && $o->id === $oc->defaultOrder->id) fw-semibold @endif"
                                               href="{{ route('orders.show', $o->id) }}?open_credit_note=1&credit_amount={{ (int) $oc->verified_credit }}">
                                                <span class="font-monospace">{{ $o->order_number }}</span>
                                                <span class="text-muted">
                                                    — Rp {{ number_format($o->deposit_paid, 0, ',', '.') }} paid of Rp {{ number_format($o->total_amount, 0, ',', '.') }}
                                                    @if($balance < 0)
                                                        (overpaid Rp {{ number_format(abs($balance), 0, ',', '.') }})
                                                    @elseif($balance > 0)
                                                        (owes Rp {{ number_format($balance, 0, ',', '.') }})
                                                    @endif
                                                    @if((float) $o->verified_paid < (float) $o->deposit_paid - 0.5)
                                                        · <span class="text-danger">Rp {{ number_format((float) $o->deposit_paid - (float) $o->verified_paid, 0, ',', '.') }} unverified</span>
                                                    @endif
                                                </span>
                                                @if($oc->defaultOrder && $o->id === $oc->defaultOrder->id)
                                                    <span class="badge bg-light text-dark border ms-1">default</span>
                                                @endif
                                            </a>
                                        </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            To resolve: open the customer's order and <strong>Void the duplicate payment</strong>, or use <strong>Credit Note</strong> above. Credit can also be left for their next order.
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
        <div class="col-4">
            <div class="card text-center py-2">
                <div class="small text-muted">Unverified</div>
                <div class="fw-semibold text-warning fs-5">{{ $verificationCounts['unverified'] ?? 0 }}</div>
            </div>
        </div>
        <div class="col-4">
            <div class="card text-center py-2">
                <div class="small text-muted">Verified</div>
                <div class="fw-semibold text-success fs-5">{{ $verificationCounts['verified'] ?? 0 }}</div>
            </div>
        </div>
        <div class="col-4">
            <div class="card text-center py-2">
                <div class="small text-muted">Disputed</div>
                <div class="fw-semibold text-danger fs-5">{{ $verificationCounts['disputed'] ?? 0 }}</div>
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
                        <td colspan="8" class="small py-2">
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
                                    data-amount="{{ (int) $payment->amount }}"
                                    title="Copy raw amount ({{ (int) $payment->amount }}) — in Excel use Find with 'Look in: Values' to match">
                                <i class="bi bi-clipboard"></i>
                            </button>
                            @if($payment->isVoided())<span class="badge bg-secondary ms-1">Voided</span>@endif
                            @if($bid && $meta && $meta['count'] > 1)
                                <span class="badge bg-info-subtle text-info-emphasis ms-1" style="font-size:.62rem;" title="Part of a Rp {{ number_format($meta['total'], 0, ',', '.') }} transfer across {{ $meta['count'] }} orders">batch</span>
                            @endif
                        </td>
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
                    <tr><td colspan="8" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
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
@elseif($tab === 'shipment')
    @php
        $shipBase  = ['trip_id' => $tripId, 'tab' => 'shipment', 'search' => $search ?? ''];
        $savedId   = session('shipment_saved');
        $shipChips = [
            ''        => ['All',     $toShipCount + $partialCount + $shippedCount],
            'pending' => ['To ship', $toShipCount],
            'partial' => ['Partly sent', $partialCount],
            'shipped' => ['All sent', $shippedCount],
        ];
    @endphp

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        @foreach($shipChips as $key => $chip)
            @php $label = $chip[0]; $n = $chip[1]; @endphp
            <a href="{{ route('payments.index', $shipBase + ($key !== '' ? ['ship' => $key] : [])) }}"
               class="btn btn-sm {{ ($shipFilter ?? '') === $key ? 'btn-dark' : 'btn-outline-secondary' }}">
                {{ $label }} <span class="badge {{ ($shipFilter ?? '') === $key ? 'bg-light text-dark' : 'bg-secondary' }} ms-1">{{ $n }}</span>
            </a>
        @endforeach
        <span class="small text-muted ms-2">
            <i class="bi bi-shield-check me-1"></i>1) set the total packages &nbsp; 2) add each sending with its date. Saved numbers are locked until you press <b>Edit</b>.
        </span>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @if(!$tripId)
                <div class="p-4 text-center text-muted">Select a trip to see customers ready to ship.</div>
            @elseif($readyToPack->isEmpty())
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-inbox d-block mb-2" style="font-size:1.5rem;"></i>
                    @if(($shipFilter ?? '') === 'pending')
                        Nothing waiting — every fully paid customer already has something sent.
                    @elseif(($shipFilter ?? '') === 'partial')
                        No customer is partly sent.
                    @elseif(($shipFilter ?? '') === 'shipped')
                        No customer has had all packages sent yet.
                    @else
                        No customers to ship yet — only customers whose orders are fully paid and all payments verified appear here.
                    @endif
                </div>
            @else
            <div class="table-responsive">
                <table class="table align-middle mb-0 shipment-table">
                    <thead class="table-light">
                        <tr>
                            <th>Customer</th>
                            <th class="text-center">Orders</th>
                            <th class="text-end">Total</th>
                            <th style="min-width:380px;">Packages</th>
                            <th class="text-center">Printed</th>
                            <th class="text-end">Invoice</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($readyToPack as $row)
                        @php
                            $cid    = $row->customer_id;
                            $ptotal = $row->package_total !== null ? (int) $row->package_total : null;
                            $psent  = (int) $row->packages_sent;
                            $pleft  = $ptotal !== null ? max(0, $ptotal - $psent) : null;
                            $done   = $ptotal !== null && $psent >= $ptotal;
                            $batches = $shipBatches[$cid] ?? collect();
                        @endphp
                        <tr id="ship-{{ $cid }}" data-name="{{ $row->customer_name }}"
                            class="ship-row {{ $done ? 'is-sent' : ($psent > 0 ? 'is-partial' : '') }} {{ (int) $savedId === (int) $cid ? 'just-saved' : '' }}">
                            <td>
                                <div class="fw-semibold">{{ $row->customer_name }}</div>
                                <div class="small text-muted">{{ $row->customer_phone }}</div>
                            </td>
                            <td class="text-center">{{ $row->order_count }}</td>
                            <td class="text-end fw-semibold">Rp {{ number_format($row->total_amount, 0, ',', '.') }}</td>

                            <td>
                            @if($ptotal === null)
                                {{-- Step 1: how many packages in total --}}
                                <form method="POST" action="{{ route('payments.shipment.save') }}" class="ship-form" data-kind="total-new">
                                    @csrf
                                    <input type="hidden" name="customer_id" value="{{ $cid }}">
                                    <input type="hidden" name="trip_id" value="{{ $tripId }}">
                                    <div class="d-flex align-items-end gap-2 flex-wrap">
                                        <div>
                                            <label class="form-label small text-muted mb-0">Total packages</label>
                                            <input type="text" name="package_total" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="off"
                                                   class="form-control ship-count" style="width:110px;" placeholder="e.g. 10">
                                        </div>
                                        <button type="submit" class="btn btn-primary ship-save"><i class="bi bi-check2 me-1"></i>Set total</button>
                                    </div>
                                </form>
                            @else
                                {{-- Progress + locked total --}}
                                <div class="ship-unit">
                                    <div class="unit-view d-flex align-items-center gap-2 flex-wrap">
                                        @if($done)
                                            <span class="badge bg-success fs-6"><i class="bi bi-check-circle me-1"></i>{{ $psent }} of {{ $ptotal }} sent</span>
                                        @elseif($psent > 0)
                                            <span class="badge bg-warning text-dark fs-6"><i class="bi bi-hourglass-split me-1"></i>{{ $psent }} of {{ $ptotal }} sent · {{ $pleft }} left</span>
                                        @else
                                            <span class="badge bg-secondary fs-6"><i class="bi bi-box-seam me-1"></i>0 of {{ $ptotal }} sent</span>
                                        @endif
                                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" onclick="shipOpen(this)">
                                            <i class="bi bi-pencil me-1"></i>Edit total
                                        </button>
                                    </div>
                                    <form method="POST" action="{{ route('payments.shipment.save') }}" class="ship-form unit-edit d-none" data-kind="total-edit">
                                        @csrf
                                        <input type="hidden" name="customer_id" value="{{ $cid }}">
                                        <input type="hidden" name="trip_id" value="{{ $tripId }}">
                                        <div class="d-flex align-items-end gap-2 flex-wrap">
                                            <div>
                                                <label class="form-label small text-muted mb-0">Total packages ({{ $psent }} already sent)</label>
                                                <input type="text" name="package_total" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="off"
                                                       class="form-control ship-count" style="width:110px;" value="{{ $ptotal }}" disabled>
                                            </div>
                                            <button type="submit" class="btn btn-primary ship-save"><i class="bi bi-check2 me-1"></i>Save total</button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="shipClose(this)">Cancel</button>
                                            <button type="button" class="btn btn-link text-danger btn-sm ms-auto" onclick="shipRemoveAll({{ $cid }})">
                                                <i class="bi bi-trash me-1"></i>Remove everything
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                {{-- Step 2: every sending so far --}}
                                @foreach($batches as $batch)
                                <div class="ship-unit border-top mt-2 pt-2">
                                    <div class="unit-view d-flex align-items-center gap-2">
                                        <span class="fw-semibold">{{ $batch->packages }} {{ $batch->packages == 1 ? 'package' : 'packages' }}</span>
                                        <span class="text-muted"><i class="bi bi-truck me-1"></i>{{ $batch->shipped_on->format('d M Y') }}</span>
                                        <button type="button" class="btn btn-sm btn-link ms-auto p-0 me-2" onclick="shipOpen(this)"><i class="bi bi-pencil me-1"></i>Edit</button>
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                                onclick="shipDeleteBatch(this, {{ $batch->id }}, '{{ $batch->packages }} package(s) on {{ $batch->shipped_on->format('d M Y') }}')"><i class="bi bi-trash me-1"></i>Delete</button>
                                    </div>
                                    <form method="POST" action="{{ route('payments.shipment.batch.update', $batch) }}" class="ship-form unit-edit d-none" data-kind="batch-edit">
                                        @csrf @method('PUT')
                                        <div class="d-flex align-items-end gap-2 flex-wrap">
                                            <div>
                                                <label class="form-label small text-muted mb-0">Packages</label>
                                                <input type="text" name="packages" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="off"
                                                       class="form-control form-control-sm ship-count" style="width:90px;" value="{{ $batch->packages }}" disabled>
                                            </div>
                                            <div>
                                                <label class="form-label small text-muted mb-0">Date</label>
                                                <input type="date" name="shipped_on" class="form-control form-control-sm ship-date" style="width:150px;"
                                                       value="{{ $batch->shipped_on->toDateString() }}" disabled>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-primary ship-save">Save</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="shipClose(this)">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                                @endforeach

                                {{-- Add another sending (not when everything is already sent) --}}
                                @if($pleft > 0)
                                <form method="POST" action="{{ route('payments.shipment.batch.store') }}" class="ship-form border-top mt-2 pt-2"
                                      data-kind="batch-add" data-left="{{ $pleft }}">
                                    @csrf
                                    <input type="hidden" name="customer_id" value="{{ $cid }}">
                                    <input type="hidden" name="trip_id" value="{{ $tripId }}">
                                    <div class="d-flex align-items-end gap-2 flex-wrap">
                                        <div>
                                            <label class="form-label small text-muted mb-0">Sent now</label>
                                            <input type="text" name="packages" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="off"
                                                   class="form-control form-control-sm ship-count" style="width:90px;" placeholder="{{ $pleft }} left">
                                        </div>
                                        <div>
                                            <label class="form-label small text-muted mb-0">Date <span>(blank = today)</span></label>
                                            <input type="date" name="shipped_on" class="form-control form-control-sm ship-date" style="width:150px;">
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary ship-save"><i class="bi bi-plus-lg me-1"></i>Add</button>
                                        <button type="submit" name="rest" value="1" class="btn btn-sm btn-outline-success ship-save"
                                                title="Log everything that is still left as sent">Send the rest ({{ $pleft }})</button>
                                    </div>
                                </form>
                                @endif
                            @endif
                            </td>

                            <td class="text-center">
                                @if($row->printed_at)
                                    <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Printed</span>
                                    <div class="small text-muted" style="font-size:.7rem;">{{ \Carbon\Carbon::parse($row->printed_at)->format('d M H:i') }}</div>
                                @else
                                    <span class="badge bg-light text-dark border">Not yet</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('orders.combined-invoice', $cid) }}?trip_id={{ $tripId }}"
                                   target="_blank" class="btn btn-sm btn-outline-primary me-1">
                                    <i class="bi bi-printer me-1"></i>Print
                                </a>
                                <a href="{{ route('orders.combined-invoice', $cid) }}?trip_id={{ $tripId }}&autoprint=1"
                                   target="_blank" class="btn btn-sm btn-outline-dark me-1"
                                   title="Opens the invoice and starts the Save-as-PDF dialog automatically">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>PDF
                                </a>
                                <form method="POST" action="{{ route('payments.mark-printed') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="customer_id" value="{{ $cid }}">
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
                <span class="small text-muted">{{ $readyToPack->total() }} fully paid customer(s)</span>
                <div>{{ $readyToPack->links() }}</div>
            </div>
            @endif
        </div>
    </div>

    {{-- Delete a sending / remove a whole shipment: their own forms, so Enter in a field can never trigger them --}}
    <form id="shipDeleteForm" method="POST" action="" class="d-none">
        @csrf @method('DELETE')
    </form>
    <form id="shipRemoveForm" method="POST" action="{{ route('payments.shipment.save') }}" class="d-none">
        @csrf
        <input type="hidden" name="customer_id" value="">
        <input type="hidden" name="trip_id" value="{{ $tripId }}">
        <input type="hidden" name="clear" value="1">
    </form>

    <style>
        .shipment-table .ship-row.is-sent { background: rgba(25,135,84,.07); }
        .shipment-table .ship-row.is-partial { background: rgba(255,193,7,.09); }
        .shipment-table .ship-row.editing { background: #fff8e1; box-shadow: inset 4px 0 0 #ffc107; }
        .shipment-table .ship-row.just-saved { animation: shipFlash 3s ease-out; }
        @keyframes shipFlash { 0%,40% { background:#cfe8ff; box-shadow: inset 4px 0 0 #0d6efd; } 100% { } }
    </style>
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

// ── Shipment tab: guard against accidental changes ──────────────────────────
(function () {
    const SEL = '.ship-form input[name=packages], .ship-form input[name=package_total], .ship-form input[type=date]';

    // Open a locked unit (the total, or one logged sending) for editing.
    window.shipOpen = function (btn) {
        const unit = btn.closest('.ship-unit'); if (!unit) return;
        unit.querySelector('.unit-view').classList.add('d-none');
        unit.querySelector('.unit-edit').classList.remove('d-none');
        unit.querySelectorAll('.unit-edit input[type=text], .unit-edit input[type=date]').forEach(i => i.disabled = false);
        btn.closest('.ship-row').classList.add('editing');
        const c = unit.querySelector('.unit-edit .ship-count'); if (c) { c.focus(); c.select(); }
    };

    // Close it again, putting back what was saved.
    window.shipClose = function (btn) {
        const unit = btn.closest('.ship-unit'); if (!unit) return;
        unit.querySelectorAll('.unit-edit input[type=text], .unit-edit input[type=date]').forEach(i => { i.value = i.defaultValue; i.disabled = true; });
        unit.querySelector('.unit-edit').classList.add('d-none');
        unit.querySelector('.unit-view').classList.remove('d-none');
        const row = btn.closest('.ship-row');
        if (!row.querySelector('.unit-edit:not(.d-none)')) row.classList.remove('editing');
    };

    window.shipDeleteBatch = function (btn, id, what) {
        const name = btn.closest('.ship-row').dataset.name;
        if (!confirm('Delete this sending of ' + name + ' (' + what + ')?\n\nThose packages go back to "left to send".')) return;
        const f = document.getElementById('shipDeleteForm');
        f.action = @json(route('payments.shipment.batch.destroy', 0)).replace(/0$/, id);
        f.submit();
    };

    window.shipRemoveAll = function (customerId) {
        const row = document.getElementById('ship-' + customerId);
        if (!confirm('Remove the WHOLE shipment of ' + row.dataset.name + '?\n\nThe total and every sending logged for it will be deleted.')) return;
        const f = document.getElementById('shipRemoveForm');
        f.querySelector('[name=customer_id]').value = customerId;
        f.submit();
    };

    // Digits only (a text box, not a number box, so the mouse wheel and arrow keys can't change it).
    document.addEventListener('input', function (e) {
        if (e.target.matches('.ship-count')) e.target.value = e.target.value.replace(/\D+/g, '');
    });

    // Say what is about to change, and stop empty or pointless saves.
    document.addEventListener('submit', function (e) {
        const form = e.target.closest('.ship-form'); if (!form) return;
        const row  = form.closest('.ship-row');
        const name = row.dataset.name;
        const kind = form.dataset.kind;
        const rest = e.submitter && e.submitter.name === 'rest';
        const countEl = form.querySelector('.ship-count');
        const dateEl  = form.querySelector('.ship-date');
        const count = countEl ? countEl.value.trim() : '';
        const date  = dateEl ? dateEl.value : '';
        const stop = m => { e.preventDefault(); if (m) alert(m); };

        if (!rest && (count === '' || parseInt(count, 10) < 1)) {
            return stop('Enter the number of packages for ' + name + '.');
        }
        let msg = null;
        if (kind === 'total-new') {
            if (parseInt(count, 10) > 20) msg = 'Total ' + count + ' packages for ' + name + '?\n\nThat is a lot — press OK only if it is right.';
        } else if (kind === 'total-edit') {
            if (count === countEl.defaultValue) { e.preventDefault(); return window.shipClose(form.querySelector('.ship-save')); }
            msg = 'Change the total packages of ' + name + '?\n\n' + countEl.defaultValue + '  →  ' + count;
        } else if (kind === 'batch-edit') {
            const lines = [];
            if (count !== countEl.defaultValue) lines.push('Packages: ' + countEl.defaultValue + '  →  ' + count);
            if (date !== dateEl.defaultValue) lines.push('Date: ' + dateEl.defaultValue + '  →  ' + date);
            if (!lines.length) { e.preventDefault(); return window.shipClose(form.querySelector('.ship-save')); }
            msg = 'Change this sending of ' + name + '?\n\n' + lines.join('\n');
        } else if (kind === 'batch-add') {
            const left = parseInt(form.dataset.left, 10);
            const when = date ? date : 'today';
            if (rest) msg = 'Mark the remaining ' + left + ' package(s) of ' + name + ' as sent (' + when + ')?';
            else if (parseInt(count, 10) > left) return stop('Only ' + left + ' package(s) are left to send to ' + name + '.');
            else if (parseInt(count, 10) > 20) msg = count + ' packages for ' + name + '?\n\nThat is a lot — press OK only if it is right.';
        }
        if (msg && !confirm(msg)) { e.preventDefault(); return; }
        form.dataset.submitting = '1';
        form.querySelectorAll('.ship-save').forEach(b => { b.disabled = true; });
        // a disabled submit button would drop its value (rest=1): send it along explicitly
        if (rest) { const h = document.createElement('input'); h.type = 'hidden'; h.name = 'rest'; h.value = '1'; form.appendChild(h); }
    });

    // Don't lose typing by accident (page change, search, filter, closing the tab).
    window.addEventListener('beforeunload', function (e) {
        if (document.querySelector('.ship-form[data-submitting="1"]')) return;
        const dirty = [...document.querySelectorAll(SEL)].some(i => !i.disabled && i.value !== i.defaultValue);
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    // After a save, bring that customer into view.
    try {
        const target = /^#ship-\d+$/.test(location.hash) && document.getElementById(location.hash.slice(1));
        if (target) target.scrollIntoView({ block: 'center' });
    } catch (err) {}
})();
</script>
@endpush

@endsection