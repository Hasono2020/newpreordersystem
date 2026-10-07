<?php

namespace App\Console\Commands;

use App\Models\InvoiceExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the server's disk from filling up with finished invoice builds (a big trip's
 * ZIP can be hundreds of MB), and tidies builds that can never finish.
 */
class PruneInvoiceExports extends Command
{
    protected $signature = 'invoices:prune {--days= : Keep finished builds this many days (default: the invoices.export_keep_days setting)}';

    protected $description = 'Delete old invoice builds and their files, stop builds that stalled, and remove orphaned folders';

    public function handle(): int
    {
        $days   = max(1, (int) ($this->option('days') ?? config('invoices.export_keep_days', 3)));
        $cutoff = now()->subDays($days);

        // 1) Finished, failed or cancelled builds past their keep-time: files and record.
        $old = InvoiceExport::whereIn('status', ['done', 'failed', 'cancelled'])
            ->where('created_at', '<', $cutoff)
            ->get();
        foreach ($old as $export) {
            $export->deleteFiles();
            $export->delete();
        }

        // 2) A build that has shown no progress for a whole day will never finish (the worker was
        //    off, the server was restarted mid-way…). Say so rather than showing "running" forever.
        $stalled = InvoiceExport::whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<', now()->subDay())
            ->update([
                'status'        => 'failed',
                'error_message' => 'Stopped: no progress for over a day (the background worker may have been switched off). Start it again.',
                'finished_at'   => now(),
            ]);

        // 3) Folders whose build record no longer exists (e.g. it was removed while a part was still being written).
        $known    = InvoiceExport::pluck('id')->map(fn ($id) => (string) $id)->all();
        $orphaned = 0;
        foreach (Storage::disk('local')->directories('invoice-exports') as $dir) {
            if (! in_array(basename($dir), $known, true)) {
                Storage::disk('local')->deleteDirectory($dir);
                $orphaned++;
            }
        }

        $this->info("Removed {$old->count()} old build(s), stopped {$stalled} stalled build(s), cleared {$orphaned} orphaned folder(s).");

        return self::SUCCESS;
    }
}
