@extends('layouts.app')
@section('title', 'Promo Rules')
@section('page-title', 'Promo Rules')

@section('content')
<div class="d-flex justify-content-between mb-3">
    <p class="text-muted mb-0 small">Promos are applied automatically based on item count and customer type when an order is saved.</p>
    <a href="{{ route('promos.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add Rule</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Min Items</th><th>Flat Discount</th><th>Per Item Discount</th><th>Free Shipping (max)</th><th>Eligible Types</th><th>Trip</th><th>Active</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($promos as $promo)
                <tr>
                    <td class="fw-semibold">{{ $promo->name }}</td>
                    <td>≥ {{ $promo->min_items }}</td>
                    <td>{{ $promo->discount_flat > 0 ? 'Rp '.number_format($promo->discount_flat, 0, ',', '.') : '—' }}</td>
                    <td>{{ $promo->discount_per_item > 0 ? 'Rp '.number_format($promo->discount_per_item, 0, ',', '.').'/item' : '—' }}</td>
                    <td>{{ $promo->max_shipping_subsidy > 0 ? 'Rp '.number_format($promo->max_shipping_subsidy, 0, ',', '.') : '—' }}</td>
                    <td class="small">
                        @if($promo->eligible_customer_types)
                            {{ implode(', ', array_map('ucfirst', $promo->eligible_customer_types)) }}
                        @else
                            All
                        @endif
                    </td>
                    <td class="small">{{ $promo->trip?->name ?? 'Global' }}</td>
                    <td>
                        @if($promo->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Off</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('promos.edit', $promo) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        <form method="POST" action="{{ route('promos.destroy', $promo) }}" class="d-inline" onsubmit="return confirm('Delete?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No promo rules yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $promos->links() }}</div>
</div>
@endsection