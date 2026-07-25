<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 'manual' = created one-at-a-time via the New Order form (where the
            // client_token guard applies). 'import' = created from an Excel batch,
            // where several genuinely separate orders for the same customer can
            // legitimately share identical items/total and the same created_at —
            // that's normal for imports, not an accidental duplicate.
            $table->enum('source', ['manual', 'import'])->default('manual')->after('created_by');
        });

        // Backfill existing data: every order created during a completed import
        // job's processing window belongs to that import, not a manual entry.
        // Without this, historical import batches would default to 'manual' and
        // immediately show up as false positives in the duplicate-order scan.
        $jobs = \DB::table('import_jobs')
            ->where('status', 'done')
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->get(['trip_id', 'started_at', 'finished_at']);

        foreach ($jobs as $job) {
            \DB::table('orders')
                ->where('trip_id', $job->trip_id)
                ->whereBetween('created_at', [$job->started_at, $job->finished_at])
                ->update(['source' => 'import']);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
