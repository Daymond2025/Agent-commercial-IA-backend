<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // DAY-2024-0001
            $table->foreignId('conversation_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();
            $table->text('delivery_address');
            $table->string('delivery_city');
            $table->decimal('total_amount', 12, 2);
            $table->string('currency', 10)->default('FCFA');
            $table->enum('status', [
                'pending',
                'confirmed',
                'processing',
                'shipped',
                'delivered',
                'cancelled'
            ])->default('pending');
            $table->foreignId('assigned_coordinator_id')->nullable()->constrained('users');
            $table->text('coordinator_notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};