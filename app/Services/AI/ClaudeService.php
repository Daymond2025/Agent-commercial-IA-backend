<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Message;
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
        $this->model = config('claude.model', 'claude-sonnet-4-6');
    }

    public function processMessage(Conversation $conversation, string $userMessage): string
    {
        $history = $this->getConversationHistory($conversation->id);
        $products = $this->getProductsCatalog();
        $systemPrompt = $this->buildSystemPrompt($conversation, $products);

        $history[] = ['role' => 'user', 'content' => $userMessage];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post("{$this->baseUrl}/messages", [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => $systemPrompt,
                'messages' => $history,
            ]);

            if ($response->failed()) {
                Log::error('Claude API error', ['body' => $response->json()]);
                return "Désolé, je rencontre un problème technique. Pouvez-vous réessayer dans quelques instants ?";
            }

            $assistantMessage = $response->json('content.0.text');

            $history[] = ['role' => 'assistant', 'content' => $assistantMessage];
            $this->saveConversationHistory($conversation->id, $history);

            return $assistantMessage;
        } catch (\Exception $e) {
            Log::error('Claude processing failed', ['error' => $e->getMessage()]);
            return "Je suis temporairement indisponible. Veuillez réessayer.";
        }
    }

    public function extractOrderData(Conversation $conversation): array
    {
        $history = $this->getConversationHistory($conversation->id);
        $products = $this->getProductsCatalog();

        $extractPrompt = "Analyse cette conversation et extrait les données de commande au format JSON strict :
{
  \"customer_name\": \"nom complet du client\",
  \"customer_phone\": \"numéro de téléphone\",
  \"customer_email\": \"email ou null\",
  \"delivery_address\": \"adresse complète\",
  \"delivery_city\": \"ville\",
  \"product_id\": ID du produit choisi (integer),
  \"confirmed\": true/false
}
Retourne UNIQUEMENT le JSON, sans aucun texte supplémentaire.";

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post("{$this->baseUrl}/messages", [
                'model' => $this->model,
                'max_tokens' => 512,
                'system' => "Tu es un extracteur de données JSON. Catalogue produits disponibles : " . json_encode($products),
                'messages' => array_merge($history, [
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

    private function buildSystemPrompt(Conversation $conversation, array $products): string
    {
        $persona = $conversation->agent->persona ?? [];
        $agentName = $persona['name'] ?? 'Awa';
        $stage = $conversation->stage;

        $productList = collect($products)->map(function ($p) {
            $specs = is_array($p['specs']) ? implode(', ', array_map(
                fn($k, $v) => "$k: $v",
                array_keys($p['specs']),
                array_values($p['specs'])
            )) : '';
            return "- {$p['name']} ({$p['brand']}): {$p['price']} | {$p['description']} | {$specs}";
        })->implode("\n");

        return <<<PROMPT
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

    private function getProductsCatalog(): array
    {
        return Product::where('is_available', true)
            ->get()
            ->map->toAgentContext()
            ->toArray();
    }
}