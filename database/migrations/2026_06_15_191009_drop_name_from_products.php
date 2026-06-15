<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'name')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'name')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('name')->nullable()->after('trip_id');
            });
        }
    }
};