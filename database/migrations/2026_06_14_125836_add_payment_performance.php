<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Speed up payment log queries (trip-scoped, sorted by date)
            $table->index(['order_id', 'paid_at'], 'pay_order_date');
            $table->index('paid_at', 'pay_date');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Speed up outstanding balance GROUP BY query
            $table->index(['trip_id', 'customer_id', 'payment_status'], 'orders_trip_cust_status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('pay_order_date');
            $table->dropIndex('pay_date');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_trip_cust_status');
        });
    }
};