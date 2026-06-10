<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_agent_id')->constrained('whatsapp_agents');
            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->enum('status', [
                'active',
                'pending_confirmation',
                'confirmed',
                'transferred',
                'abandoned',
                'completed'
            ])->default('active');
            $table->enum('stage', [
                'greeting',
                'product_selection',
                'customer_info',
                'order_summary',
                'confirmation',
                'done'
            ])->default('greeting');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('window_expires_at')->nullable(); // fenêtre 24h Meta
            $table->json('collected_data')->nullable(); // données client collectées
            $table->timestamps();

            $table->index(['customer_phone', 'whatsapp_agent_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};