<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds ONLY the indexes not already covered by 2026_06_08_000001_add_performance_indexes.
     * Focus: the payments table (verification, batch, voided) — added by this week's
     * payment-verification + batch + ready-to-pack features — plus orders.created_at
     * for the dashboard "orders today" query. Guarded so it is safe to re-run.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!$this->hasIndex('payments', 'payments_verification_idx')) {
                $table->index('verification_status', 'payments_verification_idx');
            }
            if (!$this->hasIndex('payments', 'payments_batch_idx')) {
                $table->index('batch_id', 'payments_batch_idx');
            }
            if (!$this->hasIndex('payments', 'payments_voided_idx')) {
                $table->index('voided_at', 'payments_voided_idx');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (!$this->hasIndex('orders', 'orders_created_at_idx')) {
                $table->index('created_at', 'orders_created_at_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_verification_idx');
            $table->dropIndex('payments_batch_idx');
            $table->dropIndex('payments_voided_idx');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_created_at_idx');
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $rows = \Illuminate\Support\Facades\DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]
        );
        return count($rows) > 0;
    }
};