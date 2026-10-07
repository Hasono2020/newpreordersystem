@extends('layouts.app')
@section('title', 'Invoice Downloads')
@section('page-title', 'Invoice Downloads')

@section('content')

{{-- Arrived from "Download all invoices" on a trip too big to build while you wait --}}
@if($offer)
<div class="card border-primary mb-3">
    <div class="card-body">
        <h6 class="fw-semibold mb-1"><i class="bi bi-file-earmark-pdf text-danger me-1"></i>Build all invoices for &ldquo;{{ $offer['trip']->name }}&rdquo;</h6>
        <p class="small mb-2">
            This trip has <strong>{{ number_format($offer['customers']) }} customers</strong> &mdash; too many to build while you wait,
            so it runs in the background in {{ number_format((int) ceil($offer['customers'] / $offer['perPart'])) }} parts and you
            get <strong>one ZIP</strong> when it&rsquo;s finished. One invoice per customer, A&ndash;Z.
        </p>
        <p class="small text-muted mb-3">
            Expect a few minutes for every 1,000 customers (longer when the server is busy). You can close this page or keep working &mdash;
            it carries on by itself, and the ZIP is kept for {{ (int) config('invoices.export_keep_days', 3) }} days.
        </p>
        <form method="POST" action="{{ route('trips.invoice-exports.store', $offer['trip']) }}" onsubmit="this.querySelector('button').disabled = true;">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-play-fill me-1"></i>Start building</button>
            <a href="{{ route('invoice-exports.index') }}" class="btn btn-outline-secondary btn-sm">Not now</a>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Recent builds</span>
        @if($hasActive)<span class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Updating automatically</span>@endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>Trip</th><th>Requested</th><th class="text-end">Customers</th><th style="min-width:200px;">Progress</th><th>Status</th><th class="text-end">Size</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($exports as $e)
                <tr>
                    <td>{{ $e->trip?->name ?? '—' }}</td>
                    <td class="small">
                        {{ $e->created_at->format('d M Y H:i') }}
                        <div class="text-muted">{{ $e->createdBy?->name }}</div>
                    </td>
                    <td class="text-end">{{ number_format($e->total_customers) }}</td>
                    <td>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar {{ $e->status === 'failed' ? 'bg-danger' : ($e->status === 'done' ? 'bg-success' : '') }}" style="width: {{ $e->percent() }}%"></div>
                        </div>
                        <div class="small text-muted mt-1">
                            {{ $e->percent() }}% &middot; {{ number_format($e->parts_done) }} / {{ number_format($e->total_parts) }} parts
                        </div>
                        @if($e->status === 'queued' && $e->created_at->diffInMinutes(now()) >= 3)
                            <div class="small text-warning">Still waiting for the background worker to pick it up. If this doesn&rsquo;t move, ask your admin to check the queue worker.</div>
                        @endif
                        @if($e->status === 'failed' && $e->error_message)
                            <div class="small text-danger">{{ $e->error_message }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $e->statusBadge() }}">{{ ucfirst($e->status) }}</span>
                        @if($e->status === 'done' && $e->started_at && $e->finished_at)
                            <div class="small text-muted">took {{ $e->started_at->diffForHumans($e->finished_at, true) }}</div>
                        @endif
                    </td>
                    <td class="text-end small">{{ $e->file_size ? number_format($e->file_size / 1048576, 1) . ' MB' : '—' }}</td>
                    <td class="text-end text-nowrap">
                        @if($e->status === 'done')
                            <a href="{{ route('invoice-exports.download', $e) }}" class="btn btn-sm btn-success"><i class="bi bi-download me-1"></i>Download ZIP</a>
                        @endif
                        <form method="POST" action="{{ route('invoice-exports.destroy', $e) }}" class="d-inline"
                              onsubmit="return confirm('{{ $e->isFinished() ? 'Remove this build and its file?' : 'Stop this build and remove it?' }}');">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" title="{{ $e->isFinished() ? 'Remove' : 'Stop and remove' }}"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">
                    Nothing here yet. Trips with more customers than can be built instantly appear here when you choose
                    <em>Download all invoices (PDF)</em>.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">
        Finished files are kept for {{ (int) config('invoices.export_keep_days', 3) }} days, then removed automatically.
    </div>
</div>

@endsection

@push('scripts')
@if($hasActive)
<script>
    // A build is in progress: refresh every few seconds so the progress bar moves on its own.
    setTimeout(function () { window.location.reload(); }, 5000);
</script>
@endif
@endpush
