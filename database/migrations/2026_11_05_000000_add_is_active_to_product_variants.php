<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single variant (e.g. "White / L") can be deactivated when it has sold out, without
 * deactivating the whole product. A deactivated variant is not offered for new orders; orders
 * that already contain it are untouched. Every existing variant stays active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('allocated_qty');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
