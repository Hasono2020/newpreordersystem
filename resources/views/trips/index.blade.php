@extends('layouts.app')
@section('title', 'Trips')
@section('page-title', 'Overseas Trips')

@section('content')
<div class="d-flex justify-content-between mb-3">
    <div></div>
    @if(auth()->user()->isAdmin())
    <a href="{{ route('trips.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Trip</a>
    @endif
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Destination</th><th>Trip Date</th><th>Deadline</th><th>Status</th><th>Orders</th><th>Products</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($trips as $trip)
                <tr>
                    <td class="fw-semibold">{{ $trip->name }}</td>
                    <td>{{ $trip->destination ?? '—' }}</td>
                    <td class="small">{{ $trip->trip_date?->format('d M Y') ?? '—' }}</td>
                    <td class="small">{{ $trip->order_deadline?->format('d M Y') ?? '—' }}</td>
                    <td>{!! $trip->status_badge !!}</td>
                    <td>{{ $trip->orders_count }}</td>
                    <td>{{ $trip->products_count }}</td>
                    <td>
                        <a href="{{ route('trips.show', $trip) }}" class="btn btn-sm btn-outline-primary">View</a>
                        @if(auth()->user()->isAdmin())
                        <a href="{{ route('trips.edit', $trip) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No trips yet. <a href="{{ route('trips.create') }}">Create one</a></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $trips->links() }}</div>
</div>
@endsection
