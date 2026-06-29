<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('original_filename');
            $table->string('stored_path'); // path under storage/app where the uploaded file is kept until processed
            // queued: waiting for the cron-triggered worker to pick it up
            // processing: worker has started reading/importing the file
            // done: finished successfully (see imported_count / skipped_count)
            // failed: validation errors OR an exception occurred (see error_message / row_errors)
            $table->enum('status', ['queued', 'processing', 'done', 'failed'])->default('queued');
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('imported_count')->nullable();
            $table->unsignedInteger('skipped_count')->nullable();
            $table->text('error_message')->nullable();       // top-level failure reason (e.g. "could not read file")
            $table->json('row_errors')->nullable();           // per-row validation errors, same format as the old session('import_errors')
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};