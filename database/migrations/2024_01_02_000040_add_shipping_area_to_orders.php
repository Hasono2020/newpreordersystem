<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shipping_area_id')->nullable()->constrained('shipping_areas')->nullOnDelete()->after('customer_id');
            $table->decimal('shipping_weight_gram', 10, 2)->default(0)->after('shipping_fee'); // total weight
            $table->decimal('shipping_kg_charged', 8, 2)->default(0)->after('shipping_weight_gram'); // rounded kg billed
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['shipping_area_id']);
            $table->dropColumn(['shipping_area_id', 'shipping_weight_gram', 'shipping_kg_charged']);
        });
    }
};
