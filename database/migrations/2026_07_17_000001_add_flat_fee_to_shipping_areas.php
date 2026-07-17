<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_areas', function (Blueprint $table) {
            // When set, this flat fee replaces the per-kg calculation entirely.
            // e.g. Batam = Rp 10,000 no matter how many kg.
            $table->decimal('flat_fee', 15, 2)->nullable()->after('price_per_kg');

            // Max shipping subsidy that promo can cover for this area.
            // e.g. Batam subsidy cap = 10,000 (covers the full flat fee).
            // Null means no cap (uses promo rule's max_shipping_subsidy as-is).
            $table->decimal('flat_fee_subsidy_cap', 15, 2)->nullable()->after('flat_fee');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_areas', function (Blueprint $table) {
            $table->dropColumn(['flat_fee', 'flat_fee_subsidy_cap']);
        });
    }
};
