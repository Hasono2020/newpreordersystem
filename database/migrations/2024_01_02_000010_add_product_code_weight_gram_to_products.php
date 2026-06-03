<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_code')->nullable()->after('sku');
            // rename shipping_weight to weight_gram (integer grams)
            $table->unsignedInteger('weight_gram')->default(0)->after('shipping_weight');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['product_code', 'weight_gram']);
        });
    }
};
