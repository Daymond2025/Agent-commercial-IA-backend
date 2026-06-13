<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contrôle IA par conversation
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('ai_active')->default(true)->after('stage');
            $table->timestamp('ai_paused_at')->nullable()->after('ai_active');
        });

        // Configuration relances par agent
        Schema::table('whatsapp_agents', function (Blueprint $table) {
            $table->json('relance_hours')->nullable()->after('website_url');
            // ex: [9, 14, 18] → relances à 9h, 14h et 18h
            $table->boolean('auto_relance_enabled')->default(true)->after('relance_hours');
        });

        // Templates de messages de relance
        Schema::create('relance_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('message');
            $table->string('stage_target')->nullable(); // etape visée ou null = tous
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Documents uploadés pour la base de connaissances
        Schema::create('agent_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_agent_id')->constrained('whatsapp_agents')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('file_path');
            $table->string('mime_type');
            $table->text('extracted_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_documents');
        Schema::dropIfExists('relance_templates');
        Schema::table('whatsapp_agents', function (Blueprint $table) {
            $table->dropColumn(['relance_hours', 'auto_relance_enabled']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_active', 'ai_paused_at']);
        });
    }
};