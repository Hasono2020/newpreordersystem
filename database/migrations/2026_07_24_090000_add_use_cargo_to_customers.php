<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Customer-level default: when true, every shipment for this
            // customer has 1000g added to its chargeable weight before the
            // per-kg/flat-fee formula runs. Applied once per combined
            // shipment (not once per order) — see PromoService::calcTotalWeightGram().
            $table->boolean('use_cargo')->default(false)->after('default_shipping_area_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('use_cargo');
        });
    }
};
