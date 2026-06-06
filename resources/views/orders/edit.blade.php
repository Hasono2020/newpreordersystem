@extends('layouts.app')
@section('title', 'Order '.$order->order_number)
@section('page-title', 'Order '.$order->order_number)

@section('content')
<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="{{ route('orders.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <a href="{{ route('orders.edit', $order) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    <form method="POST" action="{{ route('orders.destroy', $order) }}" onsubmit="return confirm('Delete this order?')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
    </form>
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
                        <div class="text-muted small">{{ $order->created_at->format('d M Y H:i') }}</div>
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

        {{-- Order Items --}}
        <div class="card mb-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Order Items</span>
                <div class="d-flex gap-2 align-items-center">
                    <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>To change price, edit the product</span>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#addItemPanel">
                        <i class="bi bi-plus-lg me-1"></i>Add Item
                    </button>
                </div>
            </div>

            {{-- Add item panel --}}
            <div class="collapse" id="addItemPanel">
                <div class="card-body border-bottom bg-light">
                    <form method="POST" action="{{ route('orders.items.add', $order) }}" id="addItemForm">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Product</label>
                                <select name="product_id" id="aiProduct" class="form-select form-select-sm" required onchange="fillVariantsAndPrice()">
                                    <option value="">Select…</option>
                                    @foreach($order->trip->products as $p)
                                        <option value="{{ $p->id }}"
                                            data-price="{{ $p->price }}"
                                            data-weight="{{ $p->weight_gram }}"
                                            data-variants="{{ json_encode($p->variants->map(fn($v) => ['id'=>$v->id,'label'=>$v->label,'price'=>$v->final_price])) }}">
                                            {{ $p->name }}
                                            @if($p->product_code) [{{ $p->product_code }}] @endif
                                        </option>
                                    @endforeach                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Variant</label>
                                <select name="product_variant_id" id="aiVariant" class="form-select form-select-sm" onchange="updateVariantPrice()">
                                    <option value="">No variant</option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <label class="form-label small fw-semibold">Qty</label>
                                <input type="number" name="quantity" id="aiQty" class="form-control form-control-sm" value="1" min="1" oninput="updateLineTotal()">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Price (Rp)</label>
                                <input type="number" name="unit_price" id="aiPrice" class="form-control form-control-sm" value="0" step="1000" oninput="updateLineTotal()">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label small fw-semibold">Total</label>
                                <div class="form-control form-control-sm bg-white text-muted small" id="aiLineTotal">Rp 0</div>
                            </div>
                            <div class="col-md-1">
                                <button type="submit" class="btn btn-sm btn-primary w-100 mt-3">Add</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Product</th><th>Variant</th><th>Qty</th><th>Unit Price</th><th>Line Total</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach($order->items as $item)
                        <tr>
                            <td class="fw-semibold small">
                                {{ $item->product->name }}
                                @if($item->product->product_code)
                                    <div class="text-muted font-monospace" style="font-size:.7rem;">{{ $item->product->product_code }}</div>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $item->variant?->label ?? '—' }}</td>
                            <td>
                                <form method="POST" action="{{ route('orders.items.update', [$order, $item]) }}" class="d-flex gap-1 align-items-center">
                                    @csrf @method('PATCH')
                                    <input type="number" name="quantity" value="{{ $item->quantity }}"
                                        min="1" style="width:65px;" class="form-control form-control-sm">
                                    <input type="hidden" name="unit_price" value="{{ $item->unit_price }}">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Save qty">✓</button>
                                </form>
                            </td>
                            <td class="small text-muted">
                                Rp {{ number_format($item->unit_price, 0, ',', '.') }}
                                @if($item->unit_price == 0)
                                    <span class="text-warning ms-1" title="Price is 0 — edit from Products menu">
                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                    </span>
                                @endif
                            </td>
                            <td class="fw-semibold small">Rp {{ number_format($item->line_total, 0, ',', '.') }}</td>
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
                                    <form method="POST" action="{{ route('orders.items.remove', [$order, $item]) }}" onsubmit="return confirm('Remove item?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
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
                            <div class="col-md-2">
                                <label class="form-label small">Amount (Rp)</label>
                                <input type="number" name="amount" class="form-control form-control-sm" required step="1000" min="0">
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
                            <div class="col-md-2">
                                <label class="form-label small">Method</label>
                                <input type="text" name="method" class="form-control form-control-sm" placeholder="Transfer / Cash">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Reference</label>
                                <input type="text" name="reference" class="form-control form-control-sm" placeholder="e.g. TF#123">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Date</label>
                                <input type="date" name="paid_at" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-success w-100">Save</button>
                            </div>
                        </div>
                        <div class="mt-2">
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes (optional)">
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Type</th><th>Method</th><th>Reference</th><th>Amount</th><th>By</th></tr>
                    </thead>
                    <tbody>
                        @forelse($order->payments as $payment)
                        <tr>
                            <td>{{ $payment->paid_at->format('d M Y') }}</td>
                            <td><span class="badge bg-secondary">{{ ucfirst($payment->type) }}</span></td>
                            <td>{{ $payment->method ?? '—' }}</td>
                            <td class="font-monospace">{{ $payment->reference ?? '—' }}</td>
                            <td class="{{ $payment->type === 'refund' ? 'text-danger' : 'text-success' }} fw-semibold">
                                {{ $payment->type === 'refund' ? '-' : '+' }}Rp {{ number_format($payment->amount, 0, ',', '.') }}
                            </td>
                            <td>{{ $payment->recordedBy->name }}</td>
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
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Subtotal</td><td class="text-end">Rp {{ number_format($order->subtotal, 0, ',', '.') }}</td></tr>
                    <tr><td class="text-muted">Discount</td><td class="text-end text-success">-Rp {{ number_format($order->discount_amount, 0, ',', '.') }}</td></tr>
                    <tr><td class="text-muted">Shipping Fee</td><td class="text-end">Rp {{ number_format($order->shipping_fee, 0, ',', '.') }}</td></tr>
                    <tr><td class="text-muted">Shipping Discount</td><td class="text-end text-success">-Rp {{ number_format($order->shipping_discount, 0, ',', '.') }}</td></tr>
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
            <div class="card-header bg-white py-3 fw-semibold">Update Shipping</div>
            <div class="card-body">
                <form method="POST" action="{{ route('orders.update', $order) }}">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Shipping Fee (Rp)</label>
                        <input type="number" name="shipping_fee" class="form-control form-control-sm" value="{{ $order->shipping_fee }}" step="1000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2">{{ $order->notes }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">Recalculate & Save</button>
                </form>
            </div>
        </div>
    </div>
</div>
@push('scripts')
<script>
function fillVariantsAndPrice() {
    const sel      = document.getElementById('aiProduct');
    const opt      = sel.options[sel.selectedIndex];
    const varSel   = document.getElementById('aiVariant');
    const priceIn  = document.getElementById('aiPrice');

    if (!opt.value) { varSel.innerHTML = '<option value="">No variant</option>'; return; }

    const price    = parseFloat(opt.dataset.price) || 0;
    const variants = JSON.parse(opt.dataset.variants || '[]');

    priceIn.value = price;

    varSel.innerHTML = '<option value="">No variant</option>';
    variants.forEach(v => {
        const finalPrice = parseFloat(v.price) || price;
        varSel.innerHTML += `<option value="${v.id}" data-price="${finalPrice}">
            ${v.label} — Rp ${Math.round(finalPrice).toLocaleString('id-ID')}</option>`;
    });

    updateLineTotal();
}

function updateVariantPrice() {
    const opt = document.getElementById('aiVariant').options[document.getElementById('aiVariant').selectedIndex];
    if (opt && opt.dataset.price) {
        document.getElementById('aiPrice').value = opt.dataset.price;
    }
    updateLineTotal();
}

function updateLineTotal() {
    const qty   = parseInt(document.getElementById('aiQty').value) || 0;
    const price = parseFloat(document.getElementById('aiPrice').value) || 0;
    document.getElementById('aiLineTotal').textContent = 'Rp ' + Math.round(qty * price).toLocaleString('id-ID');
}
</script>
@endpush
@endsection
