<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id', 'created_by', 'original_filename', 'stored_path',
        'status', 'total_rows', 'imported_count', 'skipped_count', 'recalculated_count',
        'error_message', 'row_errors', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'row_errors'   => 'array',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed']);
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'queued'     => '<span class="badge bg-secondary">Queued</span>',
            'processing' => '<span class="badge bg-info text-dark">Processing…</span>',
            'done'       => '<span class="badge bg-success">Done</span>',
            'failed'     => '<span class="badge bg-danger">Failed</span>',
            default      => '<span class="badge bg-light text-dark">Unknown</span>',
        };
    }
}