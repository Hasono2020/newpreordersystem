<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. "Korea Trip June 2025"
            $table->string('destination')->nullable();
            $table->date('trip_date')->nullable();
            $table->date('order_deadline')->nullable();
            $table->enum('status', ['open', 'purchasing', 'arrived', 'closed'])->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
