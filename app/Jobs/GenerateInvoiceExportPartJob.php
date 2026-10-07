<?php

namespace App\Jobs;

use App\Http\Controllers\TripInvoicePdfController;
use App\Models\InvoiceExport;
use App\Models\Trip;
use App\Services\CombinedInvoiceService;
use App\Services\InvoicePdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Builds ONE part of a background invoice export: a slice of the trip's customers
 * (A–Z), rendered to one PDF and saved to disk. It then queues the next part — or,
 * after the last one, zips every part into the single file the person downloads.
 *
 * A trip with thousands of customers can't be built in one go (time and memory), so
 * it is many short jobs rather than one long one: nothing runs long enough to time
 * out, memory is released between parts, and if the server stops partway the export
 * simply stops with its progress recorded, instead of losing everything.
 */
class GenerateInvoiceExportPartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One part is ~50 customers — seconds normally; this is only the ceiling for a very slow server. */
    public int $timeout = 900;

    /** A failure is reported on the export, not silently re-run. */
    public int $tries = 1;

    public function __construct(public int $exportId, public int $part) {}

    public function handle(CombinedInvoiceService $invoices, InvoicePdfRenderer $pdf): void
    {
        $export = InvoiceExport::find($this->exportId);
        if (! $export || $export->isFinished()) {
            return; // deleted, cancelled, or already done — nothing to do
        }

        $trip = Trip::find($export->trip_id);
        if (! $trip) {
            $this->fail($export, 'The trip no longer exists.');
            return;
        }

        @set_time_limit(600);
        $this->raiseMemoryLimitTo(512 * 1024 * 1024);
        DB::connection()->disableQueryLog();

        if ($export->status === 'queued') {
            $export->update(['status' => 'running', 'started_at' => now()]);
        }

        $perPart  = max(1, (int) $export->customers_per_part);
        $ids      = array_slice($export->customer_ids, $this->part * $perPart, $perPart);
        $invoiceList = $invoices->forCustomers($trip, $ids, $export->owner_id);

        // A slice can be empty if its orders were deleted since the request — then there's
        // just no file for that part; the export carries on.
        if ($invoiceList->isNotEmpty()) {
            $first = Str::limit(Str::slug($invoiceList->first()['customer']['name']), 18, '');
            $last  = Str::limit(Str::slug($invoiceList->last()['customer']['name']), 18, '');
            $name  = sprintf('part-%04d-of-%04d_%s-to-%s.pdf', $this->part + 1, $export->total_parts, $first ?: 'a', $last ?: 'z');

            Storage::disk('local')->put($export->directory() . '/' . $name, $pdf->render($invoiceList->all(), $trip->name));
        }

        $export->update([
            'parts_done'          => $this->part + 1,
            'processed_customers' => min(($this->part + 1) * $perPart, $export->total_customers),
        ]);

        if ($this->part + 1 < $export->total_parts) {
            static::dispatch($export->id, $this->part + 1);
            return;
        }

        $this->finalize($export->fresh(), $trip);
    }

    /** Last part done: put all the part PDFs into one ZIP and record where it is. */
    private function finalize(InvoiceExport $export, Trip $trip): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->fail($export, 'The server has no ZIP support (PHP zip extension) — ask your hosting provider to enable it.');
            return;
        }

        $dir   = Storage::disk('local')->path($export->directory());
        $files = glob($dir . '/part-*.pdf') ?: [];
        sort($files);

        if ($files === []) {
            $this->fail($export, 'No invoices were produced — the orders may have been removed while it was running.');
            return;
        }

        $zipName = 'invoices_' . (Str::slug($trip->name) ?: 'trip-' . $trip->id) . '.zip';
        $zipPath = $dir . '/' . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->fail($export, 'Could not create the ZIP file on the server (disk full or not writable?).');
            return;
        }
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
            $zip->setCompressionName(basename($file), ZipArchive::CM_STORE); // PDFs are already compressed; storing is much faster
        }
        $zip->close();

        foreach ($files as $file) {
            @unlink($file);
        }

        $export->update([
            'status'      => 'done',
            'file_path'   => $export->directory() . '/' . $zipName,
            'file_size'   => filesize($zipPath) ?: null,
            'finished_at' => now(),
        ]);
    }

    /** Anything unexpected: record it on the export so the page can say what happened. */
    public function failed(Throwable $e): void
    {
        InvoiceExport::where('id', $this->exportId)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->update([
                'status'        => 'failed',
                'error_message' => Str::limit($e->getMessage(), 500),
                'finished_at'   => now(),
            ]);
    }

    private function fail(InvoiceExport $export, string $message): void
    {
        $export->update(['status' => 'failed', 'error_message' => $message, 'finished_at' => now()]);
    }

    /** Raise PHP's memory limit to at least $bytes — never lower one already higher (or unlimited). */
    private function raiseMemoryLimitTo(int $bytes): void
    {
        $current = TripInvoicePdfController::iniBytes((string) ini_get('memory_limit'));
        if ($current === -1 || $current >= $bytes) {
            return;
        }
        @ini_set('memory_limit', (string) $bytes);
    }
}
