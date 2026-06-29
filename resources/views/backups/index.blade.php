@extends('layouts.app')
@section('title', 'Database Backups')
@section('page-title', 'Database Backups')

@section('content')

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <p class="text-muted small mb-0" style="max-width:640px;">
        <i class="bi bi-shield-check me-1"></i>
        Automatic database backups run daily at 3:00 AM (server time) and the last 7 days are kept.
        For true off-site safety, <strong>download a copy to your own computer</strong> from time to time — backups stored here are lost if the server itself fails.
    </p>
    <form method="POST" action="{{ route('backups.run') }}"
          onsubmit="document.getElementById('processingOverlay').style.display='flex'; document.getElementById('processingMsg').textContent='Creating database backup…';">
        @csrf
        <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Back up now</button>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Backup File</th>
                    <th style="width:140px;">Date</th>
                    <th style="width:90px;">Size</th>
                    <th style="width:200px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($backups as $b)
                <tr>
                    <td class="font-monospace small">{{ $b['filename'] }}</td>
                    <td class="small text-muted text-nowrap">{{ $b['created']->format('d M Y, H:i') }}</td>
                    <td class="small">{{ $b['size_mb'] }} MB</td>
                    <td class="text-end">
                        <a href="{{ route('backups.download', $b['filename']) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-download me-1"></i>Download
                        </a>
                        <form method="POST" action="{{ route('backups.destroy', $b['filename']) }}" class="d-inline"
                              onsubmit="return confirm('Delete this backup? This cannot be undone.');">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="text-center text-muted py-4">
                    No backups yet. The first one will be created automatically tonight at 3:00 AM, or click "Back up now".
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection