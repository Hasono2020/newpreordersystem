<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same batch/month/sequence machinery as order numbers (Trip::batch_number),
        // just two more counters — Sales Returns and Credit Notes each get their
        // own independent sequence, both starting at 000001 per trip/batch.
        Schema::table('trips', function (Blueprint $table) {
            $table->unsignedInteger('next_return_seq')->default(0)->after('next_order_seq');
            $table->unsignedInteger('next_credit_note_seq')->default(0)->after('next_return_seq');
        });

        Schema::create('sales_adjustments', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['return', 'credit_note']);
            $table->string('adjustment_number')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained();
            $table->decimal('amount', 15, 2); // total value of this adjustment
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            // Soft-void, same pattern as payments — a wrongly-issued return or
            // credit note gets voided (reversing its effect), never hard-deleted.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'type']);
        });

        // Only populated for type='return' — a credit note has no line items,
        // it's a pure monetary adjustment with no goods movement.
        Schema::create('sales_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_variant_id')->nullable()->constrained();
            $table->integer('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();
        });

        // Every Sales Return / Credit Note that actually refunds money creates
        // a linked Payment (type='refund') so the existing, already-correct
        // recalcPaymentStatus() math (which already knows how to subtract
        // refund-type payments) handles the balance/paid-status change with
        // no new parallel calculation path.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('sales_adjustment_id')->nullable()->after('order_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['sales_adjustment_id']);
            $table->dropColumn('sales_adjustment_id');
        });
        Schema::dropIfExists('sales_adjustment_items');
        Schema::dropIfExists('sales_adjustments');
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['next_return_seq', 'next_credit_note_seq']);
        });
    }
};
