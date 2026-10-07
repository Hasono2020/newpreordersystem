<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per "build every invoice for this trip in the background" request.
 * A trip with thousands of customers can't be built inside one web request, so the
 * work is cut into parts that run on the queue; this row is what the Invoice
 * Downloads page reads to show progress and, at the end, where the finished ZIP is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // For staff who may only see their own orders: only orders they created are included.
            $table->unsignedBigInteger('owner_id')->nullable();

            $table->string('status', 20)->default('queued');   // queued | running | done | failed | cancelled

            // The customers to include, already in A–Z order, fixed when the request was made so
            // that every part slices the same list even if orders change while it runs.
            $table->longText('customer_ids');
            $table->unsignedInteger('total_customers')->default(0);
            $table->unsignedInteger('processed_customers')->default(0);
            $table->unsignedSmallInteger('customers_per_part')->default(50);
            $table->unsignedInteger('total_parts')->default(0);
            $table->unsignedInteger('parts_done')->default(0);

            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_exports');
    }
};
