<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Canal source + session anonyme sur les conversations
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('source', 20)->default('whatsapp')->after('status');
            $table->string('session_token', 64)->nullable()->unique()->after('source');
        });

        // Slug pour URLs de pub Facebook (/chat/p/macbook-pro)
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
        });

        // Numéro d'appel exposé dans le bouton d'appel du chat web
        Schema::table('whatsapp_agents', function (Blueprint $table) {
            $table->string('support_phone', 20)->nullable()->after('phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['source', 'session_token']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
        Schema::table('whatsapp_agents', function (Blueprint $table) {
            $table->dropColumn('support_phone');
        });
    }
};