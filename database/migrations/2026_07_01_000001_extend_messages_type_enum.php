<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE messages MODIFY COLUMN `type` ENUM('text','image','audio','video','file','document','template','interactive') DEFAULT 'text'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE messages MODIFY COLUMN `type` ENUM('text','image','document','template','interactive') DEFAULT 'text'");
    }
};
