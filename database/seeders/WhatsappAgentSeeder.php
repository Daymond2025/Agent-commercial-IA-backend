<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\WhatsappAgent;
use Illuminate\Database\Seeder;

class WhatsappAgentSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::all();

        // ── Agent 1 : Awa ────────────────────────────────────────────────────
        $awa = WhatsappAgent::updateOrCreate(
            ['phone_number' => '+22507100001'],
            [
                'name'            => 'Agent Awa',
                'phone_number_id' => 'TEST_PHONE_ID_001',
                'access_token'    => 'TEST_ACCESS_TOKEN_001',
                'waba_id'         => 'TEST_WABA_001',
                'is_active'       => true,
                'avatar_url'      => 'https://ui-avatars.com/api/?name=Awa+Konate&background=3ab26a&color=fff&size=200&bold=true&rounded=true',
                'persona'         => [
                    'name'        => 'Awa',
                    'age'         => 26,
                    'style'       => 'chaleureux et professionnel',
                    'catchphrase' => 'Chez Daymond, votre satisfaction est notre priorité !',
                ],
                'instructions'    => "Commence toujours par demander le budget du client avant de proposer un produit.\nPrivilégie les produits en promotion lorsqu'ils correspondent aux besoins.\nSi le client hésite, propose une comparaison entre deux modèles.\nNe propose jamais plus de 3 produits à la fois.",
                'knowledge_base'  => "Daymond Boutique est spécialisée dans la vente d'ordinateurs portables neufs à Abidjan.\nNous livrons dans tout Abidjan sous 24h et dans les autres villes sous 48-72h.\nModes de paiement : Orange Money, MTN MoMo, Wave, virement bancaire, espèces à la livraison.\nGarantie : 12 mois sur tous les produits.\nService après-vente disponible du lundi au samedi, 8h-18h.",
                'website_url'     => 'https://daymondboutique.com',
            ]
        );
        $awa->products()->sync($products->pluck('id')->toArray());

        // ── Agent 2 : Kouadio ────────────────────────────────────────────────
        $kouadio = WhatsappAgent::updateOrCreate(
            ['phone_number' => '+22507200002'],
            [
                'name'            => 'Agent Kouadio',
                'phone_number_id' => 'TEST_PHONE_ID_002',
                'access_token'    => 'TEST_ACCESS_TOKEN_002',
                'waba_id'         => 'TEST_WABA_002',
                'is_active'       => true,
                'avatar_url'      => 'https://ui-avatars.com/api/?name=Kouadio+Yao&background=1a6b8a&color=fff&size=200&bold=true&rounded=true',
                'persona'         => [
                    'name'        => 'Kouadio',
                    'age'         => 30,
                    'style'       => 'direct et technique',
                    'catchphrase' => "Le bon PC au bon prix, c'est Daymond !",
                ],
                'instructions'    => "Tu es spécialisé dans les ordinateurs professionnels et haut de gamme.\nMets en avant les specs techniques (RAM, processeur, SSD) pour les clients exigeants.\nPropose toujours la garantie étendue et le SAV Daymond.\nPour les grandes entreprises, oriente vers l'achat en lot.",
                'knowledge_base'  => "Daymond propose des remises sur les achats groupés à partir de 3 unités.\nLivraison gratuite pour toute commande au-dessus de 400 000 FCFA.\nNous acceptons les bons de commande des entreprises.\nFinancement disponible via partenariat avec des banques locales.",
                'website_url'     => 'https://daymondboutique.com/pro',
            ]
        );
        $proProducts = $products->whereIn('brand', ['Dell', 'Apple', 'Samsung'])
            ->merge($products->where('name', 'HP EliteBook 840'));
        $kouadio->products()->sync($proProducts->pluck('id')->toArray());

        // ── Agent 3 : Mariam (inactif) ───────────────────────────────────────
        WhatsappAgent::updateOrCreate(
            ['phone_number' => '+22507300003'],
            [
                'name'            => 'Agent Mariam',
                'phone_number_id' => 'TEST_PHONE_ID_003',
                'access_token'    => 'TEST_ACCESS_TOKEN_003',
                'waba_id'         => 'TEST_WABA_003',
                'is_active'       => false,
                'avatar_url'      => 'https://ui-avatars.com/api/?name=Mariam+Diallo&background=9b59b6&color=fff&size=200&bold=true&rounded=true',
                'persona'         => [
                    'name'        => 'Mariam',
                    'age'         => 24,
                    'style'       => 'doux et empathique',
                    'catchphrase' => 'Je suis là pour vous trouver le PC parfait !',
                ],
                'instructions'    => "Spécialisée dans les ordinateurs pour étudiants et petits budgets.\nToujours rassurer le client sur la qualité des produits entrée de gamme.",
                'knowledge_base'  => null,
                'website_url'     => null,
            ]
        );
    }
}