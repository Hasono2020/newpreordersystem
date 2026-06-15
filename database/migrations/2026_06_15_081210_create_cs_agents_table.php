<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cs_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // CS agent name (handles livechat)
            $table->string('handle')->nullable();   // optional IG/WA handle
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_agents');
    }
};