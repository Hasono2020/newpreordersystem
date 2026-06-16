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
            if (!$this->indexExists('payments', 'payments_verification_idx')) {
                $table->index('verification_status', 'payments_verification_idx');
            }
            if (!$this->indexExists('payments', 'payments_batch_idx')) {
                $table->index('batch_id', 'payments_batch_idx');
            }
            if (!$this->indexExists('payments', 'payments_voided_idx')) {
                $table->index('voided_at', 'payments_voided_idx');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (!$this->indexExists('orders', 'orders_created_at_idx')) {
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

    /**
     * Database-agnostic index check (works on MySQL in production and SQLite in tests).
     * Uses Laravel's schema introspection rather than raw "SHOW INDEX" (MySQL-only).
     */
    private function indexExists(string $table, string $index): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $idx) {
                if (($idx['name'] ?? null) === $index) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }
};