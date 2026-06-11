<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsappAgent;
use Illuminate\Database\Seeder;

class ConversationSeeder extends Seeder
{
    public function run(): void
    {
        $awa     = WhatsappAgent::where('phone_number', '+22507100001')->first();
        $kouadio = WhatsappAgent::where('phone_number', '+22507200002')->first();
        $coord1  = User::where('role', 'coordinator')->first();

        // ─── 1. Active — product_selection ───────────────────────────────────
        $c1 = $this->conv($awa->id, '+22507501111', 'Kouamé Assi', 'active', 'product_selection',
            now()->subMinutes(8), now()->addHours(20),
            ['budget' => '300000', 'usage' => 'bureau et internet']
        );
        $this->msgs($c1->id, [
            ['in',  "Bonjour, je cherche un ordinateur portable",                         now()->subMinutes(25)],
            ['out', "Bonjour Kouamé ! Je suis Awa, conseillère Daymond 😊 Quel est votre budget approximatif ?", now()->subMinutes(24)],
            ['in',  "Environ 300 000 FCFA, pour le bureau et internet",                   now()->subMinutes(20)],
            ['out', "Parfait ! Pour ce budget, j'ai deux excellentes options :\n\n1️⃣ *Lenovo IdeaPad 3* — 249 000 FCFA (promo !)\n   • 8 Go RAM | 256 Go SSD | AMD Ryzen 5\n\n2️⃣ *Acer Aspire 5* — 229 000 FCFA (promo)\n   • 8 Go RAM | 256 Go SSD | Intel Core i3\n\nLequel vous intéresse le plus ?", now()->subMinutes(18)],
            ['in',  "Le Lenovo me semble bien, c'est quoi la différence avec l'Acer ?",  now()->subMinutes(8)],
        ]);

        // ─── 2. Active — customer_info ────────────────────────────────────────
        $c2 = $this->conv($awa->id, '+22505602222', 'Adjoua Soro', 'active', 'customer_info',
            now()->subMinutes(3), now()->addHours(22),
            ['product' => 'HP Pavilion 15', 'price' => 350000]
        );
        $this->msgs($c2->id, [
            ['in',  "Bonjour Daymond, est-ce que le HP Pavilion est disponible ?",    now()->subMinutes(30)],
            ['out', "Bonjour ! Oui, le *HP Pavilion 15* est disponible à 350 000 FCFA 🎉\n• 8 Go RAM | 512 Go SSD | Core i5\n• Livraison 24h à Abidjan\n\nVous souhaitez passer commande ?", now()->subMinutes(28)],
            ['in',  "Oui je veux le commander !",                                     now()->subMinutes(15)],
            ['out', "Super ! Pour préparer votre commande, donnez-moi :\n1. Votre nom complet\n2. Votre quartier / ville de livraison\n3. Votre adresse précise", now()->subMinutes(14)],
            ['in',  "Adjoua Soro, Cocody Riviera 3",                                  now()->subMinutes(3)],
        ]);

        // ─── 3. Pending confirmation — order_summary ─────────────────────────
        $c3 = $this->conv($awa->id, '+22501703333', 'Yao Brou', 'pending_confirmation', 'order_summary',
            now()->subMinutes(45), now()->addHours(18),
            ['product' => 'Acer Aspire 5', 'price' => 229000, 'name' => 'Yao Brou', 'city' => 'Abidjan']
        );
        $this->msgs($c3->id, [
            ['in',  "Bonjour, l'Acer Aspire 5 est toujours en promo ?",               now()->subHours(2)],
            ['out', "Bonjour Yao ! Oui, l'*Acer Aspire 5* est à 229 000 FCFA (au lieu de 260 000) 🎁", now()->subHours(2)->addMinutes(1)],
            ['in',  "Je prends ! Yao Brou, Yopougon Selmer, Rue des Jardins",         now()->subHours(1)->subMinutes(30)],
            ['out', "Merci Yao ! Voici le récapitulatif :\n\n📦 *Acer Aspire 5*\n💰 229 000 FCFA\n📍 Yopougon Selmer, Rue des Jardins\n\nConfirmez-vous cette commande ? Répondez *OUI* pour valider.", now()->subMinutes(45)],
        ]);

        // ─── 4. Confirmed + ORDER pending (hier) ─────────────────────────────
        $c4 = $this->conv($awa->id, '+22507804444', 'Fatou Koné', 'confirmed', 'done',
            now()->subDay()->addHours(3), now()->subHours(20),
            ['product' => 'Lenovo IdeaPad 3', 'city' => 'Abidjan']
        );
        $this->msgs($c4->id, [
            ['in',  "Bonjour, je veux le Lenovo IdeaPad en promo",       now()->subDay()],
            ['out', "Bonjour Fatou ! Le Lenovo IdeaPad 3 est à 249 000 FCFA 🌟", now()->subDay()->addMinutes(2)],
            ['in',  "OK je prends. Fatou Koné, Plateau, Avenue Marchand",now()->subDay()->addMinutes(20)],
            ['out', "Commande enregistrée ! 🎉 Réf. *DAY-2026-0001*\nNos équipes vous contactent sous peu.", now()->subDay()->addHours(1)],
            ['in',  "OUI je confirme",                                   now()->subDay()->addHours(2)],
            ['out', "Parfait ! Commande confirmée ✅ Livraison prévue demain entre 9h et 13h.", now()->subDay()->addHours(3)],
        ]);
        $this->order('DAY-2026-0001', $c4->id, 2, 'Fatou Koné', '+22507804444',
            'Avenue Marchand, Immeuble Le Plateau', 'Abidjan', 249000, 'pending', $coord1->id,
            null, now()->subDay()
        );

        // ─── 5. Confirmed + ORDER confirmed (aujourd'hui) ────────────────────
        $c5 = $this->conv($kouadio->id, '+22505905555', 'Issa Traoré', 'confirmed', 'done',
            now()->subHours(3), now()->addHours(5),
            ['product' => 'Dell Inspiron 15', 'city' => 'Abidjan']
        );
        $this->msgs($c5->id, [
            ['in',  "Bonjour, je cherche quelque chose de puissant pour mon travail",  now()->subHours(5)],
            ['out', "Bonjour Issa ! Pour les professionnels, je recommande le *Dell Inspiron 15* : Core i7, 16 Go RAM, 512 Go SSD — 420 000 FCFA.", now()->subHours(4)->subMinutes(50)],
            ['in',  "Parfait ! Issa Traoré, Marcory Zone 4",                           now()->subHours(4)],
            ['out', "Commande Réf. *DAY-2026-0002* enregistrée ✅ Paiement à la livraison.", now()->subHours(3)->subMinutes(30)],
            ['in',  "OUI confirmé",                                                    now()->subHours(3)],
        ]);
        $this->order('DAY-2026-0002', $c5->id, 3, 'Issa Traoré', '+22505905555',
            'Zone 4, Boulevard de Marseille', 'Abidjan', 420000, 'confirmed', $coord1->id,
            'Client confirmé par appel. Livraison AM.'
        );

        // ─── 6. Completed + ORDER delivered (il y a 3 jours) ─────────────────
        $c6 = $this->conv($awa->id, '+22507706666', 'Marcelline Gbagbo', 'completed', 'done',
            now()->subDays(3), now()->subDays(2),
            ['product' => 'MacBook Air M2', 'city' => 'Abidjan']
        );
        $this->msgs($c6->id, [
            ['in',  "Bonjour, le MacBook Air M2 est disponible ?",                    now()->subDays(3)->subHours(5)],
            ['out', "Bonjour Marcelline ! Oui, le *MacBook Air M2* est dispo à 950 000 FCFA. Stock limité (3 unités).", now()->subDays(3)->subHours(4)->subMinutes(55)],
            ['in',  "Je le prends immédiatement. Marcelline Gbagbo, Cocody les Deux Plateaux", now()->subDays(3)->subHours(4)],
            ['out', "Super ! Réf. *DAY-2026-0003* ✅ Livraison demain matin.",        now()->subDays(3)->subHours(3)],
            ['in',  "Reçu, merci beaucoup !",                                        now()->subDays(3)],
        ]);
        $this->order('DAY-2026-0003', $c6->id, 5, 'Marcelline Gbagbo', '+22507706666',
            'Les Deux Plateaux, Rue des Jardins Villa 12', 'Abidjan', 950000, 'delivered', $coord1->id,
            'Livré et signé. Client satisfait.', now()->subDays(3)
        );

        // ─── 7. Abandoned — lead à relancer (3h sans réponse) ────────────────
        $c7 = $this->conv($awa->id, '+22501607777', 'Diomandé Lassina', 'abandoned', 'product_selection',
            now()->subHours(3)->subMinutes(15), now()->addHours(20),
            ['budget' => '200000']
        );
        $this->msgs($c7->id, [
            ['in',  "Bonjour j'ai besoin d'un PC pas cher",                            now()->subHours(4)],
            ['out', "Bonjour Lassina ! Quel est votre budget maximum ?",               now()->subHours(3)->subMinutes(58)],
            ['in',  "200 000 FCFA maximum",                                            now()->subHours(3)->subMinutes(30)],
            ['out', "Pour ce budget, je vous recommande l'*Acer Aspire 5* en promotion à 229 000 FCFA — soit seulement 29 000 de plus. Un très bon deal ! Qu'en pensez-vous ?", now()->subHours(3)->subMinutes(20)],
        ]);

        // ─── 8. Abandoned — disparu après bonjour (lead à relancer) ──────────
        $c8 = $this->conv($kouadio->id, '+22507508888', 'Amara Diallo', 'abandoned', 'greeting',
            now()->subHours(6), now()->addHours(17), null
        );
        $this->msgs($c8->id, [
            ['in',  "Bonjour",                                                        now()->subHours(7)],
            ['out', "Bonjour Amara ! Je suis Kouadio de Daymond Boutique. Comment puis-je vous aider aujourd'hui ? 😊", now()->subHours(6)->subMinutes(58)],
        ]);

        // ─── 9. Active stagnante depuis 90 min (lead à relancer) ─────────────
        $c9 = $this->conv($awa->id, '+22505409999', 'Bintou Kouyaté', 'active', 'customer_info',
            now()->subMinutes(95), now()->addHours(22),
            ['product' => 'Asus VivoBook 15']
        );
        $this->msgs($c9->id, [
            ['in',  "Bonjour, le Asus VivoBook est disponible ?",                     now()->subHours(3)],
            ['out', "Oui Bintou ! L'*Asus VivoBook 15* est dispo à 285 000 FCFA (promo 310 000). Vous souhaitez commander ?", now()->subHours(2)->subMinutes(55)],
            ['in',  "Oui mais je dois vérifier avec mon mari d'abord",                now()->subMinutes(95)],
        ]);

        // ─── 10. Confirmed + ORDER processing (aujourd'hui) ──────────────────
        $c10 = $this->conv($kouadio->id, '+22507601010', 'Serge Amon', 'confirmed', 'done',
            now()->subHours(1), now()->addHours(22),
            ['product' => 'HP EliteBook 840', 'city' => 'Abidjan']
        );
        $this->msgs($c10->id, [
            ['in',  "Bonjour, je cherche un PC professionnel pour mon cabinet",       now()->subHours(4)],
            ['out', "Bonjour Serge ! L'*HP EliteBook 840* est notre modèle professionnel : Core i7, 16 Go RAM, coque aluminium — 580 000 FCFA.", now()->subHours(3)->subMinutes(55)],
            ['in',  "Parfait. Serge Amon, Abidjan Plateau, Tour T1 Bureau 24",        now()->subHours(2)],
            ['out', "Réf. *DAY-2026-0004* enregistrée ✅",                           now()->subHours(1)->subMinutes(30)],
            ['in',  "Confirmé !",                                                    now()->subHours(1)],
        ]);
        $this->order('DAY-2026-0004', $c10->id, 7, 'Serge Amon', '+22507601010',
            'Tour T1, Bureau 24, Plateau', 'Abidjan', 580000, 'processing', $coord1->id
        );

        // ─── 11. Confirmed + ORDER shipped ───────────────────────────────────
        $c11 = $this->conv($awa->id, '+22507511111', 'Nadia Touré', 'confirmed', 'done',
            now()->subDays(1)->subHours(2), now()->subHours(2),
            ['product' => 'Asus VivoBook 15', 'city' => 'Bouaké']
        );
        $this->msgs($c11->id, [
            ['in',  "Bonjour, livrez-vous à Bouaké ?",                               now()->subDays(2)],
            ['out', "Oui Nadia ! Livraison à Bouaké en 48-72h. L'*Asus VivoBook 15* est à 285 000 FCFA.", now()->subDays(2)->addMinutes(5)],
            ['in',  "Je prends ! Nadia Touré, Koko Quartier, Bouaké",               now()->subDays(1)->subHours(6)],
            ['out', "Réf. *DAY-2026-0005* ✅ Départ demain matin.",                 now()->subDays(1)->subHours(2)],
        ]);
        $this->order('DAY-2026-0005', $c11->id, 6, 'Nadia Touré', '+22507511111',
            'Koko Quartier, Derrière la mosquée centrale', 'Bouaké', 285000, 'shipped', $coord1->id,
            null, now()->subDays(1)
        );

        // ─── 12. Cancelled ───────────────────────────────────────────────────
        $c12 = $this->conv($kouadio->id, '+22505401212', 'Drissa Sanogo', 'abandoned', 'confirmation',
            now()->subDays(2), now()->subDays(1),
            ['product' => 'Dell Inspiron 15']
        );
        $this->msgs($c12->id, [
            ['in',  "Bonjour je veux le Dell",                                       now()->subDays(2)->subHours(3)],
            ['out', "Bonjour Drissa ! Dell Inspiron 15 à 420 000 FCFA — 16 Go RAM, Core i7.", now()->subDays(2)->subHours(2)->subMinutes(55)],
            ['in',  "Ok je prends. Drissa Sanogo, Adjamé",                          now()->subDays(2)->subHours(2)],
            ['out', "Réf. *DAY-2026-0006* ✅",                                      now()->subDays(2)->subHours(1)],
            ['in',  "Finalement j'annule, j'ai trouvé moins cher ailleurs",         now()->subDays(2)],
        ]);
        $this->order('DAY-2026-0006', $c12->id, 3, 'Drissa Sanogo', '+22505401212',
            'Adjamé, Marché de gros', 'Abidjan', 420000, 'cancelled', null,
            "Client a trouvé un autre fournisseur.", now()->subDays(2)
        );

        // ─── 13. ORDER pending ANCIENNE (test oldest_pending_hours) ──────────
        $c13 = $this->conv($awa->id, '+22507901313', 'Karidja Ouattara', 'confirmed', 'done',
            now()->subDays(2), now()->subDays(1),
            ['product' => 'HP Pavilion 15']
        );
        $this->msgs($c13->id, [
            ['in',  "Bonjour, HP Pavilion 15 dispo ?",                               now()->subDays(2)->subHours(5)],
            ['out', "Oui Karidja, dispo à 350 000 FCFA !",                          now()->subDays(2)->subHours(4)->subMinutes(58)],
            ['in',  "Karidja Ouattara, San-Pédro, Cité des Cadres",                 now()->subDays(2)->subHours(3)],
            ['out', "Réf. *DAY-2026-0007* — livraison San-Pédro sous 72h.",        now()->subDays(2)->subHours(2)],
        ]);
        $this->order('DAY-2026-0007', $c13->id, 1, 'Karidja Ouattara', '+22507901313',
            'Cité des Cadres, Rue 14', 'San-Pédro', 350000, 'pending', null,
            null, now()->subDays(2)
        );

        // ─── 14. Confirmed + ORDER confirmed (aujourd'hui — KPI revenus) ─────
        $c14 = $this->conv($awa->id, '+22507401414', 'Épiphane Yao', 'confirmed', 'done',
            now()->subMinutes(30), now()->addHours(23),
            ['product' => 'Lenovo IdeaPad 3']
        );
        $this->msgs($c14->id, [
            ['in',  "Bonjour le Lenovo en promo est encore dispo ?",                 now()->subHours(2)],
            ['out', "Bonjour Épiphane ! Oui, Lenovo IdeaPad 3 à 249 000 FCFA 🎉 Vous le prenez ?", now()->subHours(1)->subMinutes(55)],
            ['in',  "Oui ! Épiphane Yao, Treichville, Avenue 17",                   now()->subHours(1)],
            ['out', "Réf. *DAY-2026-0008* ✅ Livraison aujourd'hui avant 18h.",     now()->subMinutes(30)],
        ]);
        $this->order('DAY-2026-0008', $c14->id, 2, 'Épiphane Yao', '+22507401414',
            'Treichville, Avenue 17, Bâtiment B', 'Abidjan', 249000, 'confirmed', $coord1->id
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function conv(int $agentId, string $phone, string $name, string $status,
        string $stage, $lastMsg, $windowExp, ?array $data): Conversation
    {
        return Conversation::firstOrCreate(
            ['whatsapp_agent_id' => $agentId, 'customer_phone' => $phone],
            [
                'customer_name'    => $name,
                'status'           => $status,
                'stage'            => $stage,
                'last_message_at'  => $lastMsg,
                'window_expires_at'=> $windowExp,
                'collected_data'   => $data,
            ]
        );
    }

    private function msgs(int $convId, array $rows): void
    {
        // Skip si des messages existent déjà pour cette conversation
        if (Message::where('conversation_id', $convId)->exists()) return;

        foreach ($rows as [$dir, $content, $at]) {
            Message::create([
                'conversation_id' => $convId,
                'direction'       => $dir === 'in' ? 'inbound' : 'outbound',
                'type'            => 'text',
                'content'         => $content,
                'status'          => 'read',
                'created_at'      => $at,
                'updated_at'      => $at,
            ]);
        }
    }

    private function order(string $ref, int $convId, int $productId, string $name,
        string $phone, string $address, string $city, float $amount,
        string $status, ?int $coordId, ?string $notes = null, $createdAt = null): void
    {
        Order::firstOrCreate(
            ['reference' => $ref],
            [
                'conversation_id'         => $convId,
                'product_id'              => $productId,
                'customer_name'           => $name,
                'customer_phone'          => $phone,
                'delivery_address'        => $address,
                'delivery_city'           => $city,
                'total_amount'            => $amount,
                'currency'                => 'FCFA',
                'status'                  => $status,
                'assigned_coordinator_id' => $coordId,
                'coordinator_notes'       => $notes,
                'created_at'              => $createdAt ?? now(),
                'updated_at'              => $createdAt ?? now(),
            ]
        );
    }
}