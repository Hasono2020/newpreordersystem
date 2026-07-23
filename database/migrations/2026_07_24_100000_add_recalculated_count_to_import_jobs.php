<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            // How many distinct customers had recalcCustomerShipping() run
            // for them after this import — i.e. how many customers'
            // shipping fee/promo actually got normalised across their
            // combined orders, as opposed to left at the provisional
            // per-row value each order was inserted with.
            $table->unsignedInteger('recalculated_count')->nullable()->after('skipped_count');
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn('recalculated_count');
        });
    }
};
