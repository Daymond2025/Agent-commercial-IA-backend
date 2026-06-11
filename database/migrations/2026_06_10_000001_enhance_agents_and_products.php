<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nouvelles colonnes sur whatsapp_agents
        Schema::table('whatsapp_agents', function (Blueprint $table) {
            $table->text('instructions')->nullable()->after('persona');
            $table->longText('knowledge_base')->nullable()->after('instructions');
            $table->string('website_url')->nullable()->after('knowledge_base');
            $table->string('avatar_url')->nullable()->after('website_url');
        });

        // Nouvelles colonnes sur products
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('sale_price', 12, 2)->nullable()->after('price');
        });

        // Table pivot agent <-> product
        Schema::create('agent_product', function (Blueprint $table) {
            $table->foreignId('agent_id')->constrained('whatsapp_agents')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['agent_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_product');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sale_price');
        });

        Schema::table('whatsapp_agents', function (Blueprint $table) {
            $table->dropColumn(['instructions', 'knowledge_base', 'website_url', 'avatar_url']);
        });
    }
};