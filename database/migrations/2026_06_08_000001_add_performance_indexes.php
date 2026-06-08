<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── orders ────────────────────────────────────────────────────
        // Most critical: FIFO allocation queries orders by trip + status + ordered_at
        Schema::table('orders', function (Blueprint $table) {
            // FIFO allocation: WHERE trip_id=? AND ... ORDER BY ordered_at ASC
            $table->index(['trip_id', 'ordered_at'], 'orders_trip_ordered_at');

            // Orders list page: filter by trip + payment_status
            $table->index(['trip_id', 'payment_status'], 'orders_trip_payment_status');

            // Customer invoice / combined invoice lookups
            $table->index(['customer_id', 'trip_id'], 'orders_customer_trip');

            // Shipping area lookups (nullOnDelete cascades)
            $table->index('shipping_area_id', 'orders_shipping_area');
        });

        // ── order_items ───────────────────────────────────────────────
        // Critical for FIFO: scans order_items by product_variant + status
        Schema::table('order_items', function (Blueprint $table) {
            // FIFO allocation: WHERE product_variant_id=? AND status='pending'
            $table->index(['product_variant_id', 'status'], 'oi_variant_status');

            // Product summary page: group by product_id
            $table->index(['product_id', 'status'], 'oi_product_status');

            // Bulk deletes and invoice totals per order
            // order_id already has FK index, but compound with status helps
            $table->index(['order_id', 'status'], 'oi_order_status');
        });

        // ── customers ─────────────────────────────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            // Search by name or phone (most common customer lookup)
            $table->index('name', 'customers_name');
            $table->index('phone', 'customers_phone');
            $table->index('type', 'customers_type');
        });

        // ── products ──────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            // Products list filtered by trip
            $table->index(['trip_id', 'status'], 'products_trip_status');

            // Product code search within a trip (already unique per trip but
            // a plain index speeds up the search dropdown query)
            $table->index('product_code', 'products_code');
        });

        // ── product_variants ──────────────────────────────────────────
        // product_id already has FK index; add compound for stock queries
        Schema::table('product_variants', function (Blueprint $table) {
            // Stock allocation: WHERE product_id=? AND supplier_stock > allocated_qty
            $table->index(['product_id', 'supplier_stock', 'allocated_qty'], 'pv_product_stock');
        });

        // ── purchase_orders ───────────────────────────────────────────
        Schema::table('purchase_orders', function (Blueprint $table) {
            // PO list filtered by trip and status
            $table->index(['trip_id', 'status'], 'po_trip_status');
        });

        // ── purchase_order_items ──────────────────────────────────────
        Schema::table('purchase_order_items', function (Blueprint $table) {
            // PO show page loads all items for a PO
            $table->index(['purchase_order_id', 'product_variant_id'], 'poi_po_variant');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_trip_ordered_at');
            $table->dropIndex('orders_trip_payment_status');
            $table->dropIndex('orders_customer_trip');
            $table->dropIndex('orders_shipping_area');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('oi_variant_status');
            $table->dropIndex('oi_product_status');
            $table->dropIndex('oi_order_status');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_name');
            $table->dropIndex('customers_phone');
            $table->dropIndex('customers_type');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_trip_status');
            $table->dropIndex('products_code');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex('pv_product_stock');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('po_trip_status');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropIndex('poi_po_variant');
        });
    }
};
