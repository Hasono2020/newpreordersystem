<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('default_shipping_area_id')
                  ->nullable()
                  ->after('address')
                  ->constrained('shipping_areas')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['default_shipping_area_id']);
            $table->dropColumn('default_shipping_area_id');
        });
    }
};
