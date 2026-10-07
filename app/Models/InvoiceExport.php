<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** A background request to build every invoice for a trip — see the invoice_exports migration. */
class InvoiceExport extends Model
{
    protected $fillable = [
        'trip_id', 'created_by', 'owner_id', 'status', 'customer_ids',
        'total_customers', 'processed_customers', 'customers_per_part', 'total_parts', 'parts_done',
        'file_path', 'file_size', 'error_message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'customer_ids' => 'array',
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

    /** Nothing more will happen to it: finished, failed, or cancelled. */
    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed', 'cancelled'], true);
    }

    public function percent(): int
    {
        if ($this->status === 'done') return 100;
        return $this->total_parts > 0 ? (int) floor($this->parts_done / $this->total_parts * 100) : 0;
    }

    /** Folder (inside the app's private storage) holding this export's files. */
    public function directory(): string
    {
        return 'invoice-exports/' . $this->id;
    }

    /** Remove this export's files from disk (the row is the caller's business). */
    public function deleteFiles(): void
    {
        Storage::disk('local')->deleteDirectory($this->directory());
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'done'      => 'bg-success',
            'failed'    => 'bg-danger',
            'cancelled' => 'bg-secondary',
            'running'   => 'bg-primary',
            default     => 'bg-warning text-dark',
        };
    }
}
