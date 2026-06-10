<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone_number')->unique();
            $table->string('phone_number_id')->unique();
            $table->string('access_token');
            $table->string('waba_id');
            $table->boolean('is_active')->default(true);
            $table->json('persona')->nullable(); // personnalité de l'agent IA
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_agents');
    }
};
