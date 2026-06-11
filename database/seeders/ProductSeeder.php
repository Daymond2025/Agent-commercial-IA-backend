<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name'        => 'HP Pavilion 15',
                'brand'       => 'HP',
                'description' => 'Ordinateur portable idéal pour le bureau et les études. Clavier rétroéclairé, autonomie 8h.',
                'price'       => 350000,
                'sale_price'  => null,
                'currency'    => 'FCFA',
                'specs'       => ['RAM' => '8 Go', 'Stockage' => '512 Go SSD', 'Processeur' => 'Intel Core i5', 'Écran' => '15.6"'],
                'image_url'   => 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?w=600&q=80',
                'is_available'=> true,
                'stock'       => 10,
            ],
            [
                'name'        => 'Lenovo IdeaPad 3',
                'brand'       => 'Lenovo',
                'description' => 'Ordinateur portable polyvalent, parfait pour un usage quotidien.',
                'price'       => 280000,
                'sale_price'  => 249000,
                'currency'    => 'FCFA',
                'specs'       => ['RAM' => '8 Go', 'Stockage' => '256 Go SSD', 'Processeur' => 'AMD Ryzen 5', 'Écran' => '15.6"'],
                'image_url'   => 'https://images.unsplash.com/photo-1525547719571-a2d4ac8945e2?w=600&q=80',
                'is_available'=> true,
                'stock'       => 8,
            ],
            [
                'name'        => 'Dell Inspiron 15',
                'brand'       => 'Dell',
                'description' => 'Performances optimales pour les professionnels et entrepreneurs.',
                'price'       => 420000,
                'sale_price'  => null,
                'currency'    => 'FCFA',
                'specs'       => ['RAM' => '16 Go', 'Stockage' => '512 Go SSD', 'Processeur' => 'Intel Core i7', 'Écran' => '15.6"'],
                'image_url'   => 'https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=600&q=80',
                'is_available'=> true,
                'stock'       => 5,
            ],
            [
                'name'        => 'Acer Aspire 5',
                'brand'       => 'Acer',
                'description' => 'Bon rapport qualité-prix, robuste et fiable pour les étudiants.',
                'price'       => 260000,
                'sale_price'  => 229000,
                'currency'    => 'FCFA',
                'specs'       => ['RAM' => '8 Go', 'Stockage' => '256 Go SSD', 'Processeur' => 'Intel Core i3', 'Écran' => '15.6"'],
                'image_url'   => 'https://images.unsplash.com/photo-1588872657578-7efd1f1555ed?w=600&q=80',
                'is_available'=> true,
                'stock'       => 12,
            ],
            [
                'name'        => 'MacBook Air M2',
                'brand'       => 'Apple',
                'description' => 'Le meilleur ultrabook du marché, ultra-léger et puissant.',
                'price'       => 950000,
                'sale_price'  => null,
                'currency'    => 'FCFA',
                'specs'       => ['RAM' => '8 Go', 'Stockage' => '256 Go SSD', 'Processeur' => 'Apple M2', 'Écran' => '13.6"'],
                'image_url'   => 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=600&q=80',
                'is_available'=> true,
                'stock'       => 3,
            ],
            [
                'name'        => 'Asus VivoBook 15',
                'brand'       => 'Asus',
                'description' => 'Design élégant, dalle Full HD, excellent pour multimedia et création.',
                'price'       => 310000,
                'sale_price'  => 285000,
                'currency'    => 'FCFA',
                'specs'       => ['RAM' => '12 Go', 'Stockage' => '512 Go SSD', 'Processeur' => 'Intel Core i5', 'Écran' => '15.6" FHD'],
                'image_url'   => 'https://images.unsplash.com/photo-1544731612-de7f96afe55f?w=600&q=80',
                'is_available'=> true,
                'stock'       => 7,
            ],
            [
                'name'        => 'HP EliteBook 840',
                'brand'       => 'HP',
                'description' => 'PC professionnel haut de gamme, coque en aluminium renforcée, sécurité TPM.',
                'price'       => 580000,
                'sale_price'  => null,
                'currency'    => 'FCFA',
                'specs'       => ['RAM' => '16 Go', 'Stockage' => '512 Go SSD', 'Processeur' => 'Intel Core i7', 'Écran' => '14" FHD'],
                'image_url'   => 'https://images.unsplash.com/photo-1504707748692-419802cf939d?w=600&q=80',
                'is_available'=> true,
                'stock'       => 4,
            ],
            [
                'name'        => 'Samsung Galaxy Book2',
                'brand'       => 'Samsung',
                'description' => 'Ultrabook fin et léger, parfait pour les déplacements fréquents.',
                'price'       => 490000,
                'sale_price'  => 450000,
                'currency'    => 'FCFA',
                'specs'       => ['RAM' => '8 Go', 'Stockage' => '256 Go SSD', 'Processeur' => 'Intel Core i5', 'Écran' => '13.3" AMOLED'],
                'image_url'   => 'https://images.unsplash.com/photo-1611532736597-de2d4265fba3?w=600&q=80',
                'is_available'=> false,
                'stock'       => 0,
            ],
        ];

        foreach ($products as $p) {
            Product::updateOrCreate(['name' => $p['name'], 'brand' => $p['brand']], $p);
        }
    }
}