<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->decimal('price_adjustment', 15, 2)->default(0); // add to base price
            $table->integer('supplier_stock')->default(0); // stock available from supplier
            $table->integer('allocated_qty')->default(0); // how many allocated to orders
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
