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

        $systemPrompt = "Tu es {$agentName}, agent commercial de Daymond (vente d'ordinateurs en Côte d'Ivoire). "
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
            $msgs = $conv->messages->map(fn($m) =>
                ($m->direction === 'inbound' ? 'Client: ' : 'Agent: ') . $m->content
            )->implode("\n");
            return "=== Vente réussie (étape: {$conv->stage}) ===\n{$msgs}";
        })->implode("\n\n");

        $systemPrompt = "Tu es un expert en formation commerciale pour une boutique d'ordinateurs en Côte d'Ivoire. "
            . "Analyse les conversations WhatsApp ci-dessous qui ont abouti à des ventes confirmées. "
            . "Génère une liste d'insights concrets et actionnables (max 10 points) qui aideront l'agent à mieux vendre. "
            . "Format : bullet points courts, directs, en français. "
            . "Inclus : objections fréquentes et réponses efficaces, produits populaires et leurs arguments de vente, "
            . "séquences de questions qui font avancer le client, expressions qui déclenchent la confirmation.";

        try {
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
        // PROMPT DE BASE — identité Daymond, toujours présent
        // ---------------------------------------------------------------
        $prompt = <<<PROMPT
Tu es {$agentName}, agent commercial virtuel de Daymond, une entreprise spécialisée dans la vente d'ordinateurs en Côte d'Ivoire.

RÈGLES STRICTES :
- Réponds TOUJOURS en français, de manière chaleureuse et professionnelle
- Sois concis (messages courts, max 3 phrases par réponse)
- Ne mentionne JAMAIS que tu es une IA
- Guide le client vers l'achat sans pression
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
            $prompt .= "\n\nBASE DE CONNAISSANCES :\n{$agent->knowledge_base}";
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

            // FORMAT DE CONFIRMATION WEBCHAT : inclure les données directement dans le tag
            $prompt .= "\n\nFORMAT IMPORTANT POUR LA CONFIRMATION WEBCHAT : "
                . "Remplace [ORDER_CONFIRMED] par le tag suivant (JSON compact, sur une ligne séparée, à la fin du message) :\n"
                . "[ORDER_CONFIRMED:{\"product_id\":ID_NUMERIQUE,\"customer_name\":\"NOM\",\"customer_phone\":\"TELEPHONE\",\"delivery_address\":\"ADRESSE\",\"delivery_city\":\"VILLE\"}]\n"
                . "Le product_id doit être l'identifiant entier exact du produit dans le catalogue. "
                . "Remplace chaque valeur par les données réelles collectées durant la conversation.";
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

    private function getConversationHistory(int $conversationId): array
    {
        return Message::where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->get()
            ->map(fn($msg) => [
                'role'    => $msg->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $msg->content,
            ])
            ->toArray();
    }

    private function saveConversationHistory(int $conversationId, array $history): void
    {
        // L'historique est déjà persisté en base via ProcessWhatsAppMessage
    }
}