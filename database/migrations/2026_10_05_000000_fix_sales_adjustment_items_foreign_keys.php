<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original migration left order_item_id / product_id / product_variant_id
     * on sales_adjustment_items at the database default (RESTRICT) — meaning once
     * a Sales Return existed for an order, that order (and the product/variant it
     * referenced) became permanently undeletable: deleting its order_items hit
     * this constraint and failed outright.
     *
     * Fix: these become ON DELETE SET NULL instead. A Sales Return's own record
     * (product code, quantity, unit price, line total) is already fully captured
     * directly on sales_adjustment_items — none of that is lost. Only the live
     * link back to the specific order_item/product/variant row goes null if that
     * row is later deleted; the historical numbers stay intact either way.
     *
     * sales_adjustments.order_id itself is untouched — it stays cascadeOnDelete,
     * which is correct and unrelated to this bug: an order's OWN adjustments are
     * meant to go with it if the order itself is deleted. This fix is only about
     * the order_items step along the way no longer being blocked.
     */
    public function up(): void
    {
        Schema::table('sales_adjustment_items', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_variant_id']);
        });

        // Raw SQL rather than Schema::table()->...->change(), which needs
        // doctrine/dbal installed — this avoids depending on that.
        DB::statement('ALTER TABLE sales_adjustment_items MODIFY order_item_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE sales_adjustment_items MODIFY product_id BIGINT UNSIGNED NULL');

        Schema::table('sales_adjustment_items', function (Blueprint $table) {
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_adjustment_items', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_variant_id']);
        });
        Schema::table('sales_adjustment_items', function (Blueprint $table) {
            $table->foreign('order_item_id')->references('id')->on('order_items');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('product_variant_id')->references('id')->on('product_variants');
        });
    }
};
