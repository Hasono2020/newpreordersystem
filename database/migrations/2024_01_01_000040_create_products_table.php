<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('brand')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->decimal('price', 15, 2)->default(0); // base price in IDR
            $table->decimal('shipping_weight', 8, 2)->nullable(); // kg per item
            $table->enum('status', ['active', 'closed', 'arrived'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
