<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop the old global unique index on product_code
            $table->dropUnique(['product_code']);

            // Add new unique constraint: product_code must be unique per trip
            $table->unique(['trip_id', 'product_code'], 'products_trip_id_product_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_trip_id_product_code_unique');
            $table->unique('product_code');
        });
    }
};
