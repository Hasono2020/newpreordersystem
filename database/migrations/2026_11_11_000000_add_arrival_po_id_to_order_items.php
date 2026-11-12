<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Remember WHICH purchase order allocated (arrived / sold_out) each order
 * item, so correcting or deleting one PO only reverses its own allocation
 * and never undoes another PO's results for the same variants.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('arrival_po_id')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['arrival_po_id']);
            $table->dropColumn('arrival_po_id');
        });
    }
};
