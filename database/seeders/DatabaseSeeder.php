<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,          // 1. Utilisateurs (admin + coordinateurs)
            ProductSeeder::class,       // 2. Catalogue produits
            WhatsappAgentSeeder::class, // 3. Agents + pivot agent_product
            ConversationSeeder::class,  // 4. Conversations + messages + commandes
            FollowupSeeder::class,      // 5. Relances planifiées
        ]);
    }
}