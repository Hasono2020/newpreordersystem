<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_rules', function (Blueprint $table) {
            // JSON array of code prefixes to exclude e.g. ["MZ","NZ","PZ"]
            $table->json('excluded_product_codes')->nullable()->after('eligible_customer_types');
        });
    }

    public function down(): void
    {
        Schema::table('promo_rules', function (Blueprint $table) {
            $table->dropColumn('excluded_product_codes');
        });
    }
};
