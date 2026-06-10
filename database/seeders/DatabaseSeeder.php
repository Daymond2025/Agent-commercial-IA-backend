<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin
        User::create([
            'name' => 'Admin Daymond',
            'email' => 'admin@daymondboutique.com',
            'password' => Hash::make('Daymond@2026!'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Coordinateurs
        User::create([
            'name' => 'Coordinateur 1',
            'email' => 'coord1@daymondboutique.com',
            'password' => Hash::make('Coord1@2024!'),
            'role' => 'coordinator',
            'whatsapp_phone' => env('COORDINATOR_1_PHONE', ''),
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Coordinateur 2',
            'email' => 'coord2@daymondboutique.com',
            'password' => Hash::make('Coord2@2024!'),
            'role' => 'coordinator',
            'whatsapp_phone' => env('COORDINATOR_2_PHONE', ''),
            'is_active' => true,
        ]);

        // Produits exemple
        $products = [
            [
                'name' => 'HP Pavilion 15',
                'brand' => 'HP',
                'description' => 'Ordinateur portable idéal pour le bureau et les études.',
                'price' => 350000,
                'currency' => 'FCFA',
                'specs' => ['RAM' => '8 Go', 'Stockage' => '512 Go SSD', 'Processeur' => 'Intel Core i5', 'Écran' => '15.6"'],
                'is_available' => true,
                'stock' => 10,
            ],
            [
                'name' => 'Lenovo IdeaPad 3',
                'brand' => 'Lenovo',
                'description' => 'Ordinateur portable polyvalent, parfait pour un usage quotidien.',
                'price' => 280000,
                'currency' => 'FCFA',
                'specs' => ['RAM' => '8 Go', 'Stockage' => '256 Go SSD', 'Processeur' => 'AMD Ryzen 5', 'Écran' => '15.6"'],
                'is_available' => true,
                'stock' => 8,
            ],
            [
                'name' => 'Dell Inspiron 15',
                'brand' => 'Dell',
                'description' => 'Performances optimales pour les professionnels et entrepreneurs.',
                'price' => 420000,
                'currency' => 'FCFA',
                'specs' => ['RAM' => '16 Go', 'Stockage' => '512 Go SSD', 'Processeur' => 'Intel Core i7', 'Écran' => '15.6"'],
                'is_available' => true,
                'stock' => 5,
            ],
            [
                'name' => 'Acer Aspire 5',
                'brand' => 'Acer',
                'description' => 'Bon rapport qualité-prix, robuste et fiable.',
                'price' => 260000,
                'currency' => 'FCFA',
                'specs' => ['RAM' => '8 Go', 'Stockage' => '256 Go SSD', 'Processeur' => 'Intel Core i3', 'Écran' => '15.6"'],
                'is_available' => true,
                'stock' => 12,
            ],
            [
                'name' => 'MacBook Air M2',
                'brand' => 'Apple',
                'description' => 'Le meilleur ultrabook du marché, ultra-léger et puissant.',
                'price' => 950000,
                'currency' => 'FCFA',
                'specs' => ['RAM' => '8 Go', 'Stockage' => '256 Go SSD', 'Processeur' => 'Apple M2', 'Écran' => '13.6"'],
                'is_available' => true,
                'stock' => 3,
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}