<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Message;
use App\Models\WhatsappAgent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct()
    {
        $this->apiKey = config('claude.api_key');
        $this->model  = config('claude.model', 'claude-sonnet-4-6');
    }

    public function processMessage(Conversation $conversation, string $userMessage): string
    {
        $history      = $this->getConversationHistory($conversation->id);
        $products     = $this->getAgentProducts($conversation->agent);
        $systemPrompt = $this->buildSystemPrompt($conversation, $products);

        $history[] = ['role' => 'user', 'content' => $userMessage];

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post("{$this->baseUrl}/messages", [
                'model'      => $this->model,
                'max_tokens' => 1024,
                'system'     => $systemPrompt,
                'messages'   => $history,
            ]);

            if ($response->failed()) {
                Log::error('Claude API error', ['body' => $response->json()]);
                return "Désolé, je rencontre un problème technique. Pouvez-vous réessayer dans quelques instants ?";
            }

            $assistantMessage = $response->json('content.0.text');

            $this->saveConversationHistory($conversation->id, $history);

            return $assistantMessage;
        } catch (\Exception $e) {
            Log::error('Claude processing failed', ['error' => $e->getMessage()]);
            return "Je suis temporairement indisponible. Veuillez réessayer.";
        }
    }

    public function generateRelanceMessage(Conversation $conversation): string
    {
        $agent     = $conversation->agent;
        $persona   = $agent->persona ?? [];
        $agentName = $persona['name'] ?? 'Awa';

        $firstName = explode(' ', trim($conversation->customer_name ?? 'cher client'))[0];

        $stageLabels = [
            'greeting'          => 'le client a juste dit bonjour',
            'product_selection' => 'le client cherchait un ordinateur',
            'customer_info'     => 'le client avait choisi un produit et donnait ses infos',
            'order_summary'     => 'le récapitulatif de commande avait été envoyé',
            'confirmation'      => 'le client était sur le point de confirmer sa commande',
        ];
        $stageLabel = $stageLabels[$conversation->stage] ?? 'en cours de discussion';

        $elapsed = $conversation->last_message_at
            ? now()->diffInMinutes($conversation->last_message_at)
            : 60;
        $elapsedText = $elapsed < 60
            ? "{$elapsed} minutes"
            : round($elapsed / 60) . ' heures';

        $systemPrompt = "Tu es {$agentName}, agent commercial de WhatsApp Shop (vente d'ordinateurs en Côte d'Ivoire). "
            . "Tu dois générer UN SEUL message de relance WhatsApp court (maximum 2 phrases) et chaleureux. "
            . "N'utilise pas de formule d'intro générique. Sois naturel, direct et cordial. "
            . "Ne mentionne JAMAIS que tu es une IA.";

        $userPrompt = "Le client {$firstName} t'a contacté il y a {$elapsedText}. "
            . "Contexte : {$stageLabel}. "
            . "Génère un message de relance pour rouvrir la conversation et finaliser la vente.";

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post("{$this->baseUrl}/messages", [
                'model'      => $this->model,
                'max_tokens' => 200,
                'system'     => $systemPrompt,
                'messages'   => [['role' => 'user', 'content' => $userPrompt]],
            ]);

            return $response->json('content.0.text',
                "Bonjour {$firstName} ! Avez-vous eu le temps de réfléchir à votre ordinateur ? Je suis disponible pour finaliser votre commande 😊"
            );
        } catch (\Exception $e) {
            Log::error('Relance AI generation failed', ['error' => $e->getMessage()]);
            return "Bonjour {$firstName} ! Avez-vous eu le temps de réfléchir ? Je reste disponible pour vous aider 😊";
        }
    }

    public function extractOrderData(Conversation $conversation): array
    {
        $history  = $this->getConversationHistory($conversation->id);
        $products = $this->getAgentProducts($conversation->agent);

        // Indice produit pour les conversations webchat (client arrivé via pub Facebook)
        $productHint = '';
        if (!empty($conversation->collected_data['interested_product_name'])) {
            $pid  = $conversation->collected_data['interested_product_id']   ?? '?';
            $pname = $conversation->collected_data['interested_product_name'];
            $productHint = "NOTE IMPORTANTE : Le client est arrivé via une pub pour « {$pname} » (ID: {$pid}). "
                . "Si le produit commandé n'est pas explicitement mentionné dans la conversation, utilise cet ID.\n\n";
        }

        $extractPrompt = $productHint
            . "Analyse cette conversation et extrait les données de commande au format JSON strict :\n"
            . "{\n"
            . "  \"customer_name\": \"nom complet du client\",\n"
            . "  \"customer_phone\": \"numéro de téléphone\",\n"
            . "  \"customer_email\": \"email ou null\",\n"
            . "  \"delivery_address\": \"adresse complète\",\n"
            . "  \"delivery_city\": \"ville\",\n"
            . "  \"product_id\": ID du produit choisi (integer),\n"
            . "  \"confirmed\": true/false\n"
            . "}\n"
            . "Retourne UNIQUEMENT le JSON, sans aucun texte supplémentaire.";

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->post("{$this->baseUrl}/messages", [
                'model'      => $this->model,
                'max_tokens' => 512,
                'system'     => "Tu es un extracteur de données JSON. Catalogue produits disponibles : " . json_encode($products),
                'messages'   => array_merge($history, [
                    ['role' => 'user', 'content' => $extractPrompt]
                ]),
            ]);

            $text = $response->json('content.0.text', '{}');
            return json_decode($text, true) ?? [];
        } catch (\Exception $e) {
            Log::error('Order extraction failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Auto-formation : analyse les conversations réussies et génère des insights
     * pour enrichir la knowledge_base de l'agent.
     */
    public function trainAgent(WhatsappAgent $agent): ?string
    {
        try {
            $successfulConvs = $agent->conversations()
                ->where('status', 'confirmed')
                ->with(['messages' => fn($q) => $q->orderBy('created_at')->limit(20)])
                ->orderByDesc('updated_at')
                ->limit(15)
                ->get();

            if ($successfulConvs->count() < 3) {
                return null;
            }

            $conversationSamples = $successfulConvs->map(function ($conv) {
                $msgs = $conv->messages->map(function ($m) {
                    // Nettoie le contenu (supprime les tags binaires [R64:...])
                    $content = preg_replace('/\[R64:[A-Za-z0-9+\/=]+\]/', '', $m->content ?? '');
                    $content = preg_replace('/\[[A-Z_]+:[^\]]{0,200}\]/', '', $content);
                    return ($m->direction === 'inbound' ? 'Client: ' : 'Agent: ') . trim($content);
                })->filter()->implode("\n");
                return "=== Vente réussie (étape: {$conv->stage}) ===\n{$msgs}";
            })->implode("\n\n");

            $systemPrompt = "Tu es un expert en formation commerciale pour une boutique d'ordinateurs en Côte d'Ivoire. "
                . "Analyse les conversations WhatsApp ci-dessous qui ont abouti à des ventes confirmées. "
                . "Génère une liste d'insights concrets et actionnables (max 10 points) qui aideront l'agent à mieux vendre. "
                . "Format : bullet points courts, directs, en français. "
                . "Inclus : objections fréquentes et réponses efficaces, produits populaires et leurs arguments de vente, "
                . "séquences de questions qui font avancer le client, expressions qui déclenchent la confirmation.";

            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post("{$this->baseUrl}/messages", [
                'model'      => $this->model,
                'max_tokens' => 1500,
                'system'     => $systemPrompt,
                'messages'   => [['role' => 'user', 'content' => $conversationSamples]],
            ]);

            return $response->json('content.0.text');
        } catch (\Exception $e) {
            Log::error('Agent training failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function buildSystemPrompt(Conversation $conversation, array $products): string
    {
        $agent     = $conversation->agent;
        $persona   = $agent->persona ?? [];
        $agentName = $persona['name'] ?? 'Awa';
        $stage     = $conversation->stage;

        $productList = collect($products)->map(function ($p) {
            $specs = is_array($p['specs']) ? implode(', ', array_map(
                fn($k, $v) => "$k: $v",
                array_keys($p['specs']),
                array_values($p['specs'])
            )) : '';
            return "- {$p['name']} ({$p['brand']}): {$p['price']} | {$p['description']} | {$specs}";
        })->implode("\n");

        // ---------------------------------------------------------------
        // PROMPT DE BASE — identité WhatsApp Shop, toujours présent
        // ---------------------------------------------------------------
        $prompt = <<<PROMPT
Tu es {$agentName}, agent commercial virtuel de WhatsApp Shop, une entreprise spécialisée dans la vente d'ordinateurs en Côte d'Ivoire.

RÈGLES STRICTES :
- Réponds TOUJOURS en français, de manière professionnelle et sobre
- Sois concis (messages courts, max 3 phrases par réponse)
- Ne mentionne JAMAIS que tu es une IA
- Guide le client vers l'achat sans pression
- N'utilise JAMAIS d'emojis dans tes réponses
- Phase actuelle : {$stage}

CATALOGUE PRODUITS DISPONIBLES :
{$productList}

PROCESSUS DE VENTE :
1. greeting → Accueil chaleureux, demander ce qu'il cherche
2. product_selection → Présenter les produits adaptés, répondre aux questions techniques
3. customer_info → Collecter : nom complet, adresse de livraison, ville, téléphone de contact
4. order_summary → Récapituler la commande complète
5. confirmation → Obtenir la confirmation explicite du client ("Je confirme" ou "OUI")

IMPORTANT : Quand le client confirme sa commande, termine ton message par [ORDER_CONFIRMED]
PROMPT;

        // ---------------------------------------------------------------
        // INSTRUCTIONS SPÉCIFIQUES À CET AGENT (complément du prompt de base)
        // ---------------------------------------------------------------
        if (!empty($agent->instructions)) {
            $prompt .= "\n\nINSTRUCTIONS SPÉCIFIQUES POUR CET AGENT :\n{$agent->instructions}";
        }

        // ---------------------------------------------------------------
        // BASE DE CONNAISSANCES
        // ---------------------------------------------------------------
        if (!empty($agent->knowledge_base)) {
            $prompt .= "\n\nBASE DE CONNAISSANCES (RÈGLE ABSOLUE) :\n"
                . "Les informations ci-dessous proviennent de documents fournis par l'entreprise "
                . "(FAQ, conditions de livraison, garanties, procédures, argumentaire commercial...). "
                . "Tu DOIS les utiliser en priorité pour répondre aux questions du client dès qu'elles "
                . "sont pertinentes — livraison, garantie, paiement, retours, spécifications, politique de l'entreprise, etc. "
                . "Ne réponds JAMAIS \"je ne sais pas\" ou de façon vague si l'information se trouve ci-dessous. "
                . "Base-toi sur ces informations plutôt que sur des suppositions générales.\n\n"
                . "{$agent->knowledge_base}";
        }

        // ---------------------------------------------------------------
        // URL DU SITE / CATALOGUE EN LIGNE
        // ---------------------------------------------------------------
        if (!empty($agent->website_url)) {
            $prompt .= "\n\nSITE WEB / CATALOGUE EN LIGNE : {$agent->website_url}\nTu peux mentionner ce lien au client s'il souhaite voir plus de produits.";
        }

        // ---------------------------------------------------------------
        // CONTEXTE WEBCHAT — produit sur lequel le client a cliqué
        // ---------------------------------------------------------------
        if (($conversation->source ?? 'whatsapp') === 'webchat') {
            if (!empty($conversation->collected_data['interested_product_name'])) {
                $pName = $conversation->collected_data['interested_product_name'];
                $prompt .= "\n\nCONTEXTE : Le client a cliqué sur une publicité Facebook pour « {$pName} ». "
                    . "Oriente naturellement la conversation vers ce produit en priorité. "
                    . "Si le client ne mentionne pas de produit précis, guide-le vers celui-ci.";
            }

            // PRÉSENTATION PRODUITS WEBCHAT — JAMAIS de liste en texte brut
            $prompt .= "\n\nPRÉSENTATION PRODUITS WEBCHAT (RÈGLE ABSOLUE) : "
                . "Ne liste JAMAIS les produits sous forme de texte brut dans tes messages. "
                . "À la place, utilise ces deux tags selon le contexte :\n"
                . "1. [SUGGEST_PRODUCTS:ID1,ID2,ID3] → pour suggérer 2 à 3 produits spécifiques adaptés au besoin du client. "
                . "Choisis les IDs entiers exacts du catalogue. Exemple : [SUGGEST_PRODUCTS:2,5,8]\n"
                . "2. [SHOW_CATALOG] → pour inviter le client à parcourir tous les produits disponibles.\n"
                . "Ces tags affichent automatiquement de belles cartes visuelles. "
                . "Ton texte doit rester court et se concentrer sur la relation client, "
                . "pas sur la description des produits (les cartes s'en chargent).";

            // ENVOI D'IMAGES — CAPACITÉ RÉELLE, NE JAMAIS PRÉTENDRE LE CONTRAIRE
            $prompt .= "\n\nDEMANDE DE PHOTOS/IMAGES (RÈGLE ABSOLUE) : "
                . "Tu PEUX montrer de vraies photos des produits — ne dis JAMAIS que c'est impossible, "
                . "que tu ne peux pas envoyer d'images, ou toute phrase similaire (\"techniquement impossible\", "
                . "\"je ne peux pas partager d'images\", etc.). C'est FAUX et interdit de le dire. "
                . "Dès que le client demande à voir une photo, une image, à quoi ressemble un produit, "
                . "ou plus de visuels, réponds par une courte phrase d'accompagnement et utilise "
                . "immédiatement [SUGGEST_PRODUCTS:ID] avec l'ID du produit concerné (celui dont vous parlez, "
                . "ou le produit d'intérêt du client) — le tag affiche automatiquement ses vraies photos. "
                . "Si le client veut voir plusieurs produits, utilise [SHOW_CATALOG] à la place.";

            // FORMULAIRE COLLECTE D'INFOS WEBCHAT
            $prompt .= "\n\nFORMULAIRE INFOS CLIENT WEBCHAT : Quand tu passes à l'étape customer_info "
                . "(tu dois collecter nom, téléphone, adresse), termine ton message par [SHOW_INFO_FORM] "
                . "sur une nouvelle ligne. Ce tag affiche automatiquement un formulaire professionnel au client — "
                . "NE pose pas les questions une à une, dis juste que le formulaire va s'afficher. "
                . "Exemple : \"Pour traiter votre commande, veuillez remplir le formulaire ci-dessous.\n[SHOW_INFO_FORM]\"";

            // FORMAT DE CONFIRMATION WEBCHAT : inclure les données directement dans le tag
            $prompt .= "\n\nFORMAT IMPORTANT POUR LA CONFIRMATION WEBCHAT : "
                . "Remplace [ORDER_CONFIRMED] par le tag suivant (JSON compact, sur une ligne séparée, à la fin du message) :\n"
                . "[ORDER_CONFIRMED:{\"product_id\":ID_NUMERIQUE,\"customer_name\":\"NOM\",\"customer_phone\":\"TELEPHONE\",\"delivery_address\":\"ADRESSE\",\"delivery_city\":\"VILLE\"}]\n"
                . "Le product_id doit être l'identifiant entier exact du produit dans le catalogue. "
                . "Remplace chaque valeur par les données réelles collectées durant la conversation.\n\n"
                . "RÉCAPITULATIF VISUEL (étape order_summary) : Quand tu as collecté TOUTES les informations "
                . "(nom complet, téléphone, adresse de livraison, produit choisi), présente le récapitulatif "
                . "de commande en ajoutant ce tag sur une nouvelle ligne à la fin de ton message :\n"
                . "[ORDER_RECAP:{\"product_id\":ID_NUMERIQUE,\"customer_name\":\"NOM\",\"phone\":\"TELEPHONE\","
                . "\"delivery_address\":\"ADRESSE\",\"delivery_city\":\"VILLE\","
                . "\"delivery_date\":\"DATE OU Aujourd'hui, dans l'immédiat\","
                . "\"delivery_fee\":2000,\"tva\":0,\"remise\":0,\"bonuses\":[]}]\n"
                . "Ce tag génère une belle carte de récapitulatif. N'inclus pas de QUICK_REPLIES dans le même message. "
                . "Après ce message, attends la confirmation ou modification du client.\n\n"
                . "QUICK REPLIES WEBCHAT : À la fin de certains messages (sauf récapitulatif et confirmation), "
                . "tu PEUX ajouter des boutons de réponse rapide sur une nouvelle ligne :\n"
                . "[QUICK_REPLIES:[\"Texte bouton 1\",\"Texte bouton 2\",\"Texte bouton 3\"]]\n"
                . "Règles : max 3 boutons, texte court (max 28 caractères chacun). "
                . "Utilise-les pour les moments clés : choix de produit, ville de livraison, budget. "
                . "Exemples : [\"Moins de 500 000 F\",\"500k–1M F\",\"Plus d'1M F\"] — "
                . "[\"Abidjan\",\"Bouaké\",\"San Pedro\"]";
        }

        // ---------------------------------------------------------------
        // MODE SUPPORT POST-CONFIRMATION
        // ---------------------------------------------------------------
        if ($conversation->status === 'confirmed') {
            $prompt .= "\n\nMODE SUPPORT : La commande du client a déjà été confirmée et enregistrée. "
                . "Tu es maintenant en mode support client. "
                . "Réponds à ses questions sur la livraison, le suivi, ou aide-le à commander d'autres produits. "
                . "Ne relance PAS le processus de commande pour le même produit.";
        }

        return $prompt;
    }

    /**
     * Retourne les produits assignés à l'agent.
     * Si aucun produit assigné, retourne tous les produits disponibles.
     */
    private function getAgentProducts(WhatsappAgent $agent): array
    {
        $assignedIds = $agent->products()->pluck('products.id');

        $query = Product::where('is_available', true);

        if ($assignedIds->isNotEmpty()) {
            $query->whereIn('id', $assignedIds);
        }

        return $query->get()->map->toAgentContext()->toArray();
    }

    /**
     * Traite un fichier uploadé par le client (image via vision, audio via texte descriptif).
     */
    public function processUpload(Conversation $conversation, string $type, string $path, string $mime): string
    {
        if ($type === 'image') {
            try {
                $imageData = \Illuminate\Support\Facades\Storage::disk('public')->get($path);
                if ($imageData) {
                    return $this->processMessageWithVision($conversation, base64_encode($imageData), $mime ?: 'image/jpeg');
                }
            } catch (\Throwable $e) {
                Log::warning('Vision read failed, falling back to text', ['error' => $e->getMessage()]);
            }
        }

        $descriptions = [
            'audio' => "Le client vient d'envoyer un message vocal. Continue la conversation de façon naturelle et professionnelle en tenant compte du contexte actuel. Pose-lui une question pertinente ou propose-lui de l'aide selon l'étape de la vente. Ne mentionne pas que tu ne peux pas écouter l'audio.",
            'video' => "Le client a envoyé une vidéo. Continue la conversation naturellement.",
            'file'  => "Le client a envoyé un document. Continue la conversation naturellement.",
            'image' => "Le client a envoyé une image. Continue la conversation naturellement.",
        ];

        return $this->processMessage($conversation, $descriptions[$type] ?? "Le client a envoyé un fichier.");
    }

    /**
     * Appel Claude API avec une image en base64 (vision).
     */
    private function processMessageWithVision(Conversation $conversation, string $imageBase64, string $mimeType): string
    {
        $history      = $this->getConversationHistory($conversation->id);
        $products     = $this->getAgentProducts($conversation->agent);
        $systemPrompt = $this->buildSystemPrompt($conversation, $products);

        // Ajouter l'image comme dernier message utilisateur
        $history[] = [
            'role'    => 'user',
            'content' => [
                [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $mimeType,
                        'data'       => $imageBase64,
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => 'Le client a envoyé cette image.',
                ],
            ],
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(30)->post("{$this->baseUrl}/messages", [
                'model'    => $this->model,
                'max_tokens' => 1024,
                'system'   => $systemPrompt,
                'messages' => $history,
            ]);

            if ($response->failed()) {
                Log::error('Claude Vision API error', ['body' => $response->json()]);
                return $this->processMessage($conversation, "Le client a envoyé une image.");
            }

            return $response->json('content.0.text');
        } catch (\Exception $e) {
            Log::error('Vision processing failed', ['error' => $e->getMessage()]);
            return $this->processMessage($conversation, "Le client a envoyé une image.");
        }
    }

    private function getConversationHistory(int $conversationId): array
    {
        return Message::where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->get()
            ->map(function ($msg) {
                // Remplace le contenu JSON des médias par une description lisible
                if ($msg->type !== 'text') {
                    $desc = match($msg->type) {
                        'image' => '[image envoyée par le client]',
                        'audio' => '[message vocal envoyé par le client]',
                        'video' => '[vidéo envoyée par le client]',
                        default => '[fichier envoyé par le client]',
                    };
                    return ['role' => 'user', 'content' => $desc];
                }
                // Nettoie les tags binaires du contenu texte
                $content = preg_replace('/\[R64:[A-Za-z0-9+\/=]+\]/', '', $msg->content ?? '');
                $content = trim($content);
                return [
                    'role'    => $msg->direction === 'inbound' ? 'user' : 'assistant',
                    'content' => $content,
                ];
            })
            ->filter(fn($msg) => $msg['content'] !== '')
            ->values()
            ->toArray();
    }

    private function saveConversationHistory(int $conversationId, array $history): void
    {
        // L'historique est déjà persisté en base via ProcessWhatsAppMessage
    }
}