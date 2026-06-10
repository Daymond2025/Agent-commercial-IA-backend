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
            $table->string('name');
            $table->string('brand')->nullable();
            $table->text('description');
            $table->decimal('price', 12, 2);
            $table->string('currency', 10)->default('FCFA');
            $table->json('specs')->nullable(); // RAM, CPU, stockage, écran...
            $table->string('image_url')->nullable();
            $table->boolean('is_available')->default(true);
            $table->integer('stock')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};