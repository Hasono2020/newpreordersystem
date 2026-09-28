<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // Nullable and left blank on existing trips on purpose — orders
            // keep their current random ORD-xxxxxxxx codes unless the trip
            // has a batch_number, so old trips (and their existing orders)
            // are completely unaffected. Only trips created/assigned a
            // batch number from now on get the new sequential format.
            $table->unsignedInteger('batch_number')->nullable()->unique()->after('name');

            // The next sequence number to hand out for this trip's orders.
            // Incremented atomically (locked) at order-creation time, so it
            // never issues the same number twice even under concurrent
            // order creation, and never leaves gaps from failed/rolled-back
            // attempts since the increment lives in the same transaction as
            // the order itself.
            $table->unsignedInteger('next_order_seq')->default(0)->after('batch_number');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['batch_number', 'next_order_seq']);
        });
    }
};
