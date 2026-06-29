@extends('layouts.app')
@section('title', 'Activity Log')
@section('page-title', 'Activity Log')

@section('content')

<div class="mb-3">
    <p class="text-muted small mb-0">
        <i class="bi bi-shield-check me-1"></i>Audit trail of money-critical actions — payments, voids, and order deletions/edits. Records are kept permanently and cannot be edited.
    </p>
</div>

{{-- Filter bar --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-auto">
                <label class="form-label small mb-1">Staff</label>
                <select name="user_id" class="form-select form-select-sm" style="width:auto;">
                    <option value="">All staff</option>
                    @foreach($staffList as $staff)
                        <option value="{{ $staff->id }}" {{ request('user_id') == $staff->id ? 'selected' : '' }}>{{ $staff->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">Action</label>
                <select name="action" class="form-select form-select-sm" style="width:auto;">
                    <option value="">All actions</option>
                    @foreach($actionTypes as $key => $label)
                        <option value="{{ $key }}" {{ request('action') == $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
            </div>
            <div class="col">
                <label class="form-label small mb-1">Search description</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="order #, customer, reason…" value="{{ request('search') }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-secondary">Filter</button>
                @if(request()->anyFilled(['user_id','action','date_from','date_to','search']))
                    <a href="{{ route('activity-logs.index') }}" class="btn btn-sm btn-link">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:150px;">When</th>
                    <th style="width:130px;">Who</th>
                    <th style="width:160px;">Action</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                <tr>
                    <td class="small text-muted text-nowrap">{{ $log->created_at->format('d M Y, H:i') }}</td>
                    <td class="small fw-semibold">{{ $log->user_name }}</td>
                    <td>{!! $log->actionBadge() !!}</td>
                    <td class="small">
                        {{ $log->description }}
                        @if($log->changes)
                        <button class="btn btn-sm btn-link p-0 ms-1 align-baseline" type="button"
                                data-bs-toggle="collapse" data-bs-target="#changes-{{ $log->id }}">
                            view changes
                        </button>
                        <div class="collapse mt-1" id="changes-{{ $log->id }}">
                            <table class="table table-sm table-bordered mb-0" style="font-size:.75rem;">
                                <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                                <tbody>
                                @foreach($log->changes as $field => $vals)
                                    <tr>
                                        <td class="fw-semibold">{{ $field }}</td>
                                        <td class="text-danger">{{ $vals['old'] ?? '—' }}</td>
                                        <td class="text-success">{{ $vals['new'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="text-center text-muted py-4">No activity logged yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
        <span class="small text-muted">{{ $logs->total() }} entries</span>
        <div>{{ $logs->links() }}</div>
    </div>
</div>

@endsection