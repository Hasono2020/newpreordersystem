<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Seed default values
        DB::table('settings')->insert([
            ['key' => 'store_name',    'value' => 'PreOrder System', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'store_tagline', 'value' => 'Overseas Shopping Service', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'store_phone',   'value' => '', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'store_address', 'value' => '', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
