<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\Trip;
use App\Services\OrderImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Large files can legitimately take a few minutes — give the worker
     * plenty of room (the cron-triggered worker process has its own
     * --max-time cap separately, see the cron setup notes).
     */
    public int $timeout = 600;

    /** Don't retry automatically — a failed import should be reviewed, not silently re-run. */
    public int $tries = 1;

    public function __construct(public int $importJobId) {}

    public function handle(OrderImportService $importService): void
    {
        $importJob = ImportJob::find($this->importJobId);
        if (!$importJob) return; // record was deleted — nothing to do

        $importJob->update(['status' => 'processing', 'started_at' => now()]);

        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');
        DB::connection()->disableQueryLog();

        try {
            $trip = Trip::find($importJob->trip_id);
            if (!$trip) {
                $importJob->update([
                    'status'        => 'failed',
                    'error_message' => 'Trip no longer exists.',
                    'finished_at'   => now(),
                ]);
                return;
            }

            $absolutePath = Storage::path($importJob->stored_path);

            $validated = $importService->readAndValidate($absolutePath, $trip);

            if (!empty($validated['errors'])) {
                $importJob->update([
                    'status'      => 'failed',
                    'row_errors'  => $validated['errors'],
                    'total_rows'  => count($validated['rows']),
                    'finished_at' => now(),
                ]);
                return;
            }

            $importJob->update(['total_rows' => count($validated['rows'])]);

            $result = $importService->importRows($validated['rows'], $trip, $importJob->created_by);

            $importJob->update([
                'status'         => 'done',
                'imported_count' => $result['imported'],
                'skipped_count'  => $result['skipped'],
                'finished_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            $importJob->update([
                'status'        => 'failed',
                'error_message' => 'Unexpected error: ' . $e->getMessage(),
                'finished_at'   => now(),
            ]);
            report($e); // still goes to the normal Laravel log for debugging
        } finally {
            // Clean up the uploaded file regardless of outcome
            Storage::delete($importJob->stored_path);
        }
    }
}