@extends('layouts.app')
@section('title', 'Order '.$order->order_number)
@section('page-title', 'Order '.$order->order_number)

@section('content')
<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="{{ \App\Http\Middleware\RememberListUrl::returnUrl('orders') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
@if(auth()->user()->hasPermission('orders.edit') && (auth()->user()->isAdmin() || auth()->user()->role !== 'staff' || $order->created_by === auth()->id()))
    <a href="{{ route('orders.edit', $order) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    @endif
    @if(auth()->user()->hasPermission('orders.delete') && auth()->user()->isAdmin())
    <form method="POST" action="{{ route('orders.destroy', $order) }}" onsubmit="return confirm('Delete this order?')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
    </form>
    @endif
    @if(auth()->user()->hasPermission('orders.create'))
    <a href="{{ route('orders.create', ['trip_id' => $order->trip_id]) }}" class="btn btn-sm btn-success ms-auto">
        <i class="bi bi-plus-lg me-1"></i>New Order
    </a>
    @endif
</div>

<div class="row g-3">
    {{-- Left: Order info + items --}}
    <div class="col-lg-8">

        {{-- Header info --}}
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="text-muted small">Customer</div>
                        <div class="fw-semibold">{{ $order->customer->name }}</div>
                        <div class="text-muted small">{{ $order->customer->type_label }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Trip</div>
                        <div class="fw-semibold">{{ $order->trip->name }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Created by</div>
                        <div>{{ $order->createdBy->name }}</div>
                        @if($order->csAgent)
                        <div class="text-muted small mt-1">CS: <span class="text-dark">{{ $order->csAgent->name }}</span></div>
                        @endif
                        <div class="text-muted small">{{ $order->created_at->format('d M Y H:i') }}</div>
                        <div class="small mt-1">
                            <span class="text-muted">Ordered:</span>
                            <strong>{{ ($order->ordered_at ?? $order->created_at)->format('d M Y H:i') }}</strong>
                            @if($order->ordered_at && $order->ordered_at->format('Y-m-d H:i') !== $order->created_at->format('Y-m-d H:i'))
                                <span class="badge bg-warning text-dark" style="font-size:.6rem;">backdated</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Payment</div>
                        <div>{!! $order->payment_status_badge !!}</div>
                    </div>
                </div>
                @if($order->notes)
                    <div class="mt-3 p-2 bg-light rounded small"><i class="bi bi-sticky me-1"></i>{{ $order->notes }}</div>
                @endif
            </div>
        </div>

        {{-- Warning: no shipping area --}}
        @if(!$order->shipping_area_id)
        <div class="alert alert-warning py-2 small mb-3">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong>No shipping area set.</strong>
            Shipping fee is Rp 0 and promo shipping discounts cannot be applied.
            Use <strong>Shipping & Recalculate</strong> on the right to set an area and recalculate.
        </div>
        @endif

        {{-- Order Items --}}
        <div class="card mb-3">
            <div class="card-header bg-white py-3">
                <span class="fw-semibold">Order Items</span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th style="width:52px;"></th><th>Product</th><th>Variant</th><th>Qty</th><th>Unit Price</th><th>Line Total</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach($order->items as $item)
                        @php $soldOut = $item->status === 'sold_out'; @endphp
                        <tr class="{{ $soldOut ? 'text-muted' : '' }}">
                            <td>
                                @if($item->product?->image)
                                    <img src="{{ asset('storage/'.$item->product->image) }}" width="40" height="40"
                                        style="object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;{{ $soldOut ? 'opacity:.5;' : '' }}" alt="">
                                @else
                                    <div style="width:40px;height:40px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;">
                                        <i class="bi bi-image text-muted" style="font-size:.85rem;"></i>
                                    </div>
                                @endif
                            </td>
                            <td class="fw-semibold small">
                                {{ $item->product->product_code ?? '—' }}
                            </td>
                            <td class="small text-muted">{{ $item->variant?->label ?? '—' }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td class="small">
                                {{ $soldOut ? 'Rp 0' : 'Rp '.number_format($item->unit_price, 0, ',', '.') }}
                                @if(!$soldOut && $item->unit_price == 0)
                                    <i class="bi bi-exclamation-triangle-fill text-warning" title="Price is 0"></i>
                                @endif
                            </td>
                            <td class="fw-semibold small">
                                {{ $soldOut ? 'Rp 0' : 'Rp '.number_format($item->line_total, 0, ',', '.') }}
                            </td>
                            <td>{!! $item->status_badge !!}</td>
                            <td>
                                <div class="d-flex gap-1">
                                    <form method="POST" action="{{ route('orders.items.status', [$order, $item]) }}">
                                        @csrf @method('PATCH')
                                        <select name="status" class="form-select form-select-sm" style="width:110px;" onchange="this.form.submit()">
                                            @foreach(['pending','confirmed','purchased','arrived','sold_out','cancelled'] as $s)
                                                <option value="{{ $s }}" {{ $item->status == $s ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Payments history --}}
        <div class="card">
            <div class="card-header bg-white py-3 d-flex justify-content-between">
                <span class="fw-semibold">Payment History</span>
                <button class="btn btn-sm btn-outline-success" data-bs-toggle="collapse" data-bs-target="#addPaymentPanel">
                    <i class="bi bi-plus-lg me-1"></i>Record Payment
                </button>
            </div>

            <div class="collapse" id="addPaymentPanel">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('orders.payments.add', $order) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small">Amount (Rp)</label>
                                <input type="number" name="amount" class="form-control form-control-sm" required step="1" min="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Type</label>
                                <select name="type" class="form-select form-select-sm">
                                    <option value="deposit">Deposit</option>
                                    <option value="partial">Partial</option>
                                    <option value="full">Full Payment</option>
                                    <option value="refund">Refund</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Reference</label>
                                <input type="text" name="reference" class="form-control form-control-sm" placeholder="e.g. TF#123">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Date</label>
                                <input type="date" name="paid_at" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small d-block">&nbsp;</label>
                                <button type="submit" class="btn btn-sm btn-success w-100">Save</button>
                            </div>
                        </div>
                        <div class="mt-2">
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes (optional)">
                        </div>
                        <div class="mt-2 small text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Use <strong>Refund</strong> when giving money back to the customer (e.g. partial refund of an overpayment). It subtracts from the paid total. For a full duplicate payment, use <strong>Void</strong> on that payment row instead.
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Type</th><th>Reference</th><th>Amount</th><th>By</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($order->payments as $payment)
                        <tr class="{{ $payment->isVoided() ? 'text-decoration-line-through text-muted opacity-50' : '' }}">
                            <td>{{ $payment->paid_at->format('d M Y') }}</td>
                            <td>
                                <span class="badge {{ $payment->isVoided() ? 'bg-danger' : 'bg-secondary' }}">
                                    {{ $payment->isVoided() ? 'VOIDED' : ucfirst($payment->type) }}
                                </span>
                            </td>
                            <td class="font-monospace">{{ $payment->reference ?? '—' }}</td>
                            <td class="{{ $payment->isVoided() ? '' : ($payment->type === 'refund' ? 'text-danger' : 'text-success') }} fw-semibold">
                                {{ $payment->type === 'refund' ? '-' : '+' }}Rp {{ number_format($payment->amount, 0, ',', '.') }}
                            </td>
                            <td class="small">
                                {{ $payment->recordedBy->name }}
                                @if($payment->isVoided())
                                <div class="text-danger small mt-1">
                                    Voided by {{ $payment->voidedBy->name }} on {{ $payment->voided_at->format('d M Y H:i') }}<br>
                                    Reason: {{ $payment->void_reason }}
                                </div>
                                @endif
                            </td>
                            <td>
                                @if(!$payment->isVoided() && auth()->user()->hasPermission('payments.void'))
                                <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1"
                                    style="font-size:.75rem;"
                                    onclick="showVoidModal({{ $payment->id }}, {{ $payment->amount }})">
                                    Void
                                </button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No payments recorded</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Right: Summary --}}
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header bg-white py-3 fw-semibold">Order Summary</div>
            <div class="card-body">
                {{-- Promo status banner --}}
                @if($appliedPromo)
                    <div class="alert alert-success py-2 px-3 mb-3 small">
                        <i class="bi bi-tag-fill me-1"></i>
                        <strong>Promo applied:</strong> {{ $appliedPromo['rule']->name }}
                        @if($appliedPromo['discount'] > 0)
                            — <span class="text-success">-Rp {{ number_format($appliedPromo['discount'], 0, ',', '.') }} discount</span>
                        @endif
                        @if($appliedPromo['max_shipping_subsidy'] > 0)
                            @if($order->shipping_discount >= $order->shipping_fee && $order->shipping_fee > 0)
                                — <span class="text-success">FREE shipping</span>
                            @else
                                — <span class="text-success">up to Rp {{ number_format($appliedPromo['max_shipping_subsidy'], 0, ',', '.') }} shipping subsidy</span>
                            @endif
                        @endif
                    </div>
                @else
                    <div class="alert alert-light border py-2 px-3 mb-3 small text-muted">
                        <i class="bi bi-tag me-1"></i>No promo applied
                        @if($nextRule)
                            @php $itemCount = $order->items->whereNotIn('status',['cancelled','sold_out'])->sum('quantity'); @endphp
                            — needs {{ $nextRule->min_items - $itemCount }} more item(s) for <strong>{{ $nextRule->name }}</strong>
                        @endif
                    </div>
                @endif

                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Subtotal</td><td class="text-end">Rp {{ number_format($order->subtotal, 0, ',', '.') }}</td></tr>
                    <tr>
                        <td class="text-muted">
                            Discount
                            @if($order->discount_amount > 0 && $appliedPromo)
                                <div class="small text-success">{{ $appliedPromo['rule']->name }}</div>
                            @endif
                        </td>
                        <td class="text-end text-success">-Rp {{ number_format($order->discount_amount, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">
                            Shipping
                            @if($order->shippingArea)
                                <div class="small text-info">{{ $order->shippingArea->name }}</div>
                                <div class="small text-muted">{{ number_format($order->shipping_weight_gram, 0, ',', '.') }}g → {{ $order->shipping_kg_charged }} kg</div>
                            @endif
                        </td>
                        <td class="text-end">Rp {{ number_format($order->shipping_fee, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">
                            Shipping Discount
                            @if($order->shipping_discount > 0 && $order->shipping_discount >= $order->shipping_fee)
                                <span class="badge bg-success ms-1" style="font-size:.65rem;">FREE</span>
                            @endif
                        </td>
                        <td class="text-end text-success">-Rp {{ number_format($order->shipping_discount, 0, ',', '.') }}</td>
                    </tr>
                    <tr class="fw-bold border-top"><td>Total</td><td class="text-end fs-5">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</td></tr>
                    <tr class="text-success"><td>Paid</td><td class="text-end">Rp {{ number_format($order->deposit_paid, 0, ',', '.') }}</td></tr>
                    <tr class="{{ $order->remaining_balance > 0 ? 'text-danger' : 'text-success' }} fw-bold">
                        <td>Balance Due</td>
                        <td class="text-end">Rp {{ number_format($order->remaining_balance, 0, ',', '.') }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white py-3 fw-semibold">Shipping & Recalculate</div>
            <div class="card-body">
                <form method="POST" action="{{ route('orders.update', $order) }}">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Shipping Area</label>
                        <select name="shipping_area_id" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            @foreach($shippingAreas as $area)
                                <option value="{{ $area->id }}" {{ $order->shipping_area_id == $area->id ? 'selected' : '' }}>
                                    {{ $area->name }}{{ $area->province ? ' ('.$area->province.')' : '' }}
                                    — {{ $area->isFlatFee() ? 'Flat Rp '.number_format($area->flat_fee,0,',','.') : 'Rp '.number_format($area->price_per_kg,0,',','.').' /kg' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">
                            Order Date &amp; Time
                            <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">FIFO</span>
                        </label>
                        <input type="datetime-local" name="ordered_at" class="form-control form-control-sm"
                            value="{{ $order->ordered_at?->format('Y-m-d\TH:i') ?? $order->created_at->format('Y-m-d\TH:i') }}">
                        <div class="form-text" style="font-size:.7rem;">When customer actually ordered. Adjust to fix missed orders.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2">{{ $order->notes }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-arrow-repeat me-1"></i>Recalculate & Save
                    </button>
                    <div class="form-text mt-2 text-muted">
                        Use this after editing a product's weight to recalculate shipping fee.
                    </div>
                </form>
            </div>
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
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>Voiding does not delete the record.</strong> It marks the payment as invalid and removes it from the balance calculation. A full audit trail is kept.
                    </div>
                    <p class="mb-2">You are voiding: <strong id="voidAmountDisplay"></strong></p>
                    <label class="form-label fw-semibold">Reason for voiding <span class="text-danger">*</span></label>
                    <textarea name="void_reason" class="form-control" rows="3"
                        placeholder="e.g. Customer confirmed only 1 transfer was made. Second entry was a data entry error by staff."
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
function showVoidModal(paymentId, amount) {
    const form = document.getElementById('voidPaymentForm');
    form.action = `/payments/${paymentId}/void`;
    form.querySelector('textarea').value = '';
    document.getElementById('voidAmountDisplay').textContent =
        'Rp ' + amount.toLocaleString('id-ID');
    new bootstrap.Modal(document.getElementById('voidPaymentModal')).show();
}
</script>
@endpush

@endsection