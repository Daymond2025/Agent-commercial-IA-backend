<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\NotifyCoordinators;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\WhatsappAgent;
use App\Services\AI\ClaudeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebChatController extends Controller
{
    // ── Infos publiques produit + agent ──────────────────────────────────────

    public function product(string $identifier): JsonResponse
    {
        $product = Product::where('is_available', true)
            ->where(function ($q) use ($identifier) {
                $q->where('slug', $identifier)
                  ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0);
            })
            ->first();

        $agent = $product
            ? $this->findAgentForProduct($product)
            : WhatsappAgent::where('is_active', true)->first();

        return response()->json([
            'product' => $product ? [
                'id'          => $product->id,
                'name'        => $product->name,
                'brand'       => $product->brand,
                'description' => $product->description,
                'price'       => $product->formatted_price,
                'sale_price'  => $product->formatted_sale_price,
                'image_url'   => $product->image_url,
                'images'      => $product->images ?: array_filter([$product->image_url]),
                'slug'        => $product->slug,
                'specs'       => $product->specs,
            ] : null,
            'agent' => $agent ? [
                'name'          => $agent->persona['name'] ?? $agent->name,
                'avatar_url'    => $agent->avatar_url,
                'support_phone' => $agent->support_phone,
            ] : null,
        ]);
    }

    // ── Démarrer une conversation ─────────────────────────────────────────────

    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
        ]);

        $product = $request->product_id ? Product::find($request->product_id) : null;
        $agent   = $product
            ? $this->findAgentForProduct($product)
            : WhatsappAgent::where('is_active', true)->first();

        if (!$agent) {
            return response()->json(['error' => 'Aucun agent disponible pour le moment.'], 503);
        }

        $token = Str::uuid()->toString();

        $conversation = Conversation::create([
            'whatsapp_agent_id' => $agent->id,
            'customer_phone'    => 'webchat_' . substr(str_replace('-', '', $token), 0, 10),
            'source'            => 'webchat',
            'session_token'     => $token,
            'status'            => 'active',
            'stage'             => 'greeting',
            'ai_active'         => true,
            'last_message_at'   => now(),
            'window_expires_at' => now()->addHours(24),
            'collected_data'    => $product ? [
                'interested_product_id'   => $product->id,
                'interested_product_name' => $product->name,
            ] : [],
        ]);

        $agentName = $agent->persona['name'] ?? $agent->name;

        if ($product) {
            $displayPrice = $product->sale_price
                ? number_format($product->sale_price, 0, ',', ' ') . ' ' . $product->currency . ' (promo)'
                : number_format($product->price, 0, ',', ' ') . ' ' . $product->currency;

            $welcome = "Bonjour, je suis {$agentName}.\n"
                . "Je vois que vous vous intéressez au *{$product->name}*"
                . ($product->brand ? " ({$product->brand})" : '')
                . " à *{$displayPrice}*. Excellent choix.\n\n"
                . "Je suis à votre disposition pour vous accompagner jusqu'à la livraison.\n"
                . "Comment puis-je vous appeler ?";
        } else {
            $welcome = "Bonjour, je suis {$agentName}.\n"
                . "Bienvenue chez *WhatsApp Shop*, votre spécialiste en ordinateurs en Côte d'Ivoire.\n\n"
                . "Je suis là pour vous aider à trouver l'ordinateur idéal selon vos besoins et votre budget.\n"
                . "Comment puis-je vous appeler ?";
        }

        $welcomeMsg = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'text',
            'content'         => $welcome,
            'status'          => 'sent',
        ]);

        return response()->json([
            'session_token'   => $token,
            'welcome_message' => [
                'id'         => $welcomeMsg->id,
                'content'    => $welcome,
                'created_at' => $welcomeMsg->created_at,
            ],
            'agent' => [
                'name'          => $agentName,
                'avatar_url'    => $agent->avatar_url,
                'support_phone' => $agent->support_phone,
            ],
            'product' => $product ? [
                'id'         => $product->id,
                'name'       => $product->name,
                'brand'      => $product->brand,
                'price'      => $product->formatted_price,
                'sale_price' => $product->formatted_sale_price,
                'image_url'  => $product->image_url,
                'images'     => $product->images ?: array_filter([$product->image_url]),
            ] : null,
        ], 201);
    }

    // ── Client envoie un message texte — traitement IA SYNCHRONE ─────────────

    public function message(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $conversation = Conversation::with('agent')
            ->where('session_token', $token)
            ->whereNotIn('status', ['completed', 'abandoned'])
            ->first();

        if (!$conversation) {
            return response()->json(['error' => 'Session introuvable ou conversation terminée.'], 404);
        }

        // Sauvegarder le message client
        $clientMsg = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'type'            => 'text',
            'content'         => $validated['message'],
            'status'          => 'delivered',
        ]);

        $conversation->update([
            'last_message_at'   => now(),
            'window_expires_at' => now()->addHours(24),
        ]);

        $agentMessage       = null;
        $orderRecap         = null;
        $showInfoForm       = false;
        $productSuggestions = null;
        $showCatalog        = false;

        // Traitement IA SYNCHRONE — réponse incluse dans la même requête
        if ($conversation->ai_active) {
            try {
                $claude     = app(ClaudeService::class);
                $aiResponse = $claude->processMessage($conversation, $validated['message']);

                // ── Étape 1 : détecter ORDER_CONFIRMED ───────────────────
                $embeddedData = [];
                $confirmed    = false;

                if (preg_match('/\[ORDER_CONFIRMED:(\{.*?\})\]/s', $aiResponse, $matches)) {
                    $confirmed    = true;
                    $embeddedData = json_decode($matches[1], true) ?? [];
                    $cleanText    = trim(preg_replace('/\[ORDER_CONFIRMED:\{.*?\}\]/s', '', $aiResponse));
                } elseif (str_contains($aiResponse, '[ORDER_CONFIRMED]')) {
                    $confirmed = true;
                    $cleanText = trim(str_replace('[ORDER_CONFIRMED]', '', $aiResponse));
                } else {
                    $cleanText = $aiResponse;
                }

                // ── Étape 2 : détecter ORDER_RECAP (uniquement si pas encore confirmé) ──
                if (!$confirmed && preg_match('/\[ORDER_RECAP:(\{.*?\})\]/s', $cleanText, $recapMatches)) {
                    $recapData = json_decode($recapMatches[1], true) ?? [];
                    $cleanText = trim(preg_replace('/\[ORDER_RECAP:\{.*?\}\]/s', '', $cleanText));

                    if (!empty($recapData['product_id'])) {
                        $rProduct = Product::find((int) $recapData['product_id']);
                        if ($rProduct) {
                            $price       = (float) ($rProduct->sale_price ?? $rProduct->price ?? 0);
                            $deliveryFee = (int)   ($recapData['delivery_fee'] ?? 2000);
                            $tva         = (int)   ($recapData['tva']          ?? 0);
                            $remise      = (int)   ($recapData['remise']       ?? 0);
                            $total       = $price + $deliveryFee + $tva - $remise;
                            $fmt         = fn($n) => number_format((int) $n, 0, ',', ' ') . ' FCFA';

                            $addr        = trim(($recapData['delivery_address'] ?? '') . ', ' . ($recapData['delivery_city'] ?? ''), ' ,');

                            $orderRecap = [
                                'product_name'     => $rProduct->name,
                                'product_image'    => $rProduct->image_url,
                                'price'            => $fmt($price),
                                'customer_name'    => $recapData['customer_name'] ?? '',
                                'delivery_address' => $addr,
                                'phone'            => $recapData['phone'] ?? ($recapData['customer_phone'] ?? ''),
                                'delivery_date'    => $recapData['delivery_date'] ?? "Aujourd'hui, dans l'immédiat",
                                'delivery_fee'     => $deliveryFee,
                                'tva'              => $tva,
                                'remise'           => $remise,
                                'total'            => $fmt($total),
                                'bonuses'          => is_array($recapData['bonuses'] ?? null) ? $recapData['bonuses'] : [],
                                'currency'         => 'FCFA',
                            ];
                        }
                    }
                }

                // ── Étape 2b : sauvegarder recap enrichi dans le contenu (persistence reload) ──
                if ($orderRecap !== null) {
                    $encoded    = base64_encode(json_encode($orderRecap, JSON_UNESCAPED_UNICODE));
                    $cleanText .= "\n[R64:{$encoded}]";
                }

                // ── Étape 2c : détecter SHOW_INFO_FORM ───────────────────────────────────────
                if (str_contains($cleanText, '[SHOW_INFO_FORM]')) {
                    $showInfoForm = true;
                    $cleanText    = trim(str_replace('[SHOW_INFO_FORM]', '', $cleanText));
                }

                // ── Étape 2d : détecter SUGGEST_PRODUCTS ─────────────────────────────────────
                if (!$confirmed && preg_match('/\[SUGGEST_PRODUCTS:([\d,\s]+)\]/i', $cleanText, $spMatches)) {
                    $ids     = array_filter(array_map('intval', explode(',', $spMatches[1])));
                    $cleanText = trim(preg_replace('/\[SUGGEST_PRODUCTS:[\d,\s]+\]/i', '', $cleanText));
                    if (!empty($ids)) {
                        $spProducts = Product::whereIn('id', array_values($ids))
                            ->where('is_available', true)
                            ->get();
                        if ($spProducts->isNotEmpty()) {
                            $productSuggestions = $spProducts->map(fn($p) => [
                                'id'          => $p->id,
                                'name'        => $p->name,
                                'brand'       => $p->brand ?? '',
                                'description' => $p->description,
                                'price'       => $p->formatted_price,
                                'sale_price'  => $p->formatted_sale_price,
                                'image_url'   => $p->image_url,
                                'images'      => $p->images ?: array_filter([$p->image_url]),
                                'specs'       => $p->specs,
                                'slug'        => $p->slug,
                            ])->values()->toArray();
                        }
                    }
                }

                // ── Étape 2e : détecter SHOW_CATALOG ─────────────────────────────────────────
                if (!$confirmed && str_contains($cleanText, '[SHOW_CATALOG]')) {
                    $showCatalog = true;
                    $cleanText   = trim(str_replace('[SHOW_CATALOG]', '', $cleanText));
                }

                // ── Étape 3 : détecter QUICK_REPLIES (désactivé si recap ou confirmation) ──
                $quickReplies = [];
                if (!$confirmed && $orderRecap === null && preg_match('/\[QUICK_REPLIES:(\[.*?\])\]/s', $cleanText, $qrMatches)) {
                    $decoded = json_decode($qrMatches[1], true);
                    if (is_array($decoded)) {
                        $quickReplies = array_values(array_slice(
                            array_filter($decoded, fn($r) => is_string($r) && strlen($r) <= 30),
                            0, 3
                        ));
                    }
                    $cleanText = trim(preg_replace('/\[QUICK_REPLIES:\[.*?\]\]/s', '', $cleanText));
                }

                $agentMsg = Message::create([
                    'conversation_id' => $conversation->id,
                    'direction'       => 'outbound',
                    'type'            => 'text',
                    'content'         => $cleanText,
                    'status'          => 'sent',
                ]);

                $agentMessage = $this->formatMessage($agentMsg);
                if (!empty($quickReplies)) {
                    $agentMessage['quick_replies'] = $quickReplies;
                }

                if ($confirmed) {
                    $this->handleOrderConfirmed($conversation, $claude, $embeddedData);
                }

            } catch (\Exception $e) {
                Log::error('WebChat AI failed', [
                    'conv'  => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                $errMsg = Message::create([
                    'conversation_id' => $conversation->id,
                    'direction'       => 'outbound',
                    'type'            => 'text',
                    'content'         => "Désolé, je rencontre un problème technique. Pouvez-vous réessayer dans quelques instants ?",
                    'status'          => 'sent',
                ]);

                $agentMessage = $this->formatMessage($errMsg);
            }
        }

        return response()->json([
            'id'                  => $clientMsg->id,
            'status'              => 'received',
            'agent_message'       => $agentMessage,
            'order_recap'         => $orderRecap,
            'show_info_form'      => $showInfoForm,
            'product_suggestions' => $productSuggestions,
            'show_catalog'        => $showCatalog,
        ], 201);
    }

    // ── Upload photo / fichier / vocal ────────────────────────────────────────

    public function upload(Request $request, string $token): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|max:20480', // 20 Mo max
            ]);

            $conversation = Conversation::where('session_token', $token)
                ->whereNotIn('status', ['completed', 'abandoned'])
                ->first();

            if (!$conversation) {
                return response()->json(['error' => 'Session introuvable.'], 404);
            }

            $file = $request->file('file');

            // PHP finfo classe parfois audio/webm comme video/webm — on préfère le MIME client pour l'audio
            $finfoMime  = $file->getMimeType() ?? '';
            $clientMime = strtolower(explode(';', $file->getClientMimeType() ?? '')[0]);
            $mime       = $finfoMime ?: $clientMime;

            // Si finfo dit video/ mais le client déclare audio/, on fait confiance au client
            if (str_starts_with($clientMime, 'audio/') && str_starts_with($mime, 'video/')) {
                $mime = $clientMime;
            }

            // Fallback par extension du nom de fichier si le MIME reste inconnu
            if (!$mime || $mime === 'application/octet-stream') {
                $ext  = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
                $mime = match($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png'         => 'image/png',
                    'gif'         => 'image/gif',
                    'webp'        => 'image/webp',
                    'bmp'         => 'image/bmp',
                    'mp3'         => 'audio/mpeg',
                    'ogg'         => 'audio/ogg',
                    'webm'        => 'audio/webm',
                    'wav'         => 'audio/wav',
                    'aac', 'm4a'  => 'audio/aac',
                    'mp4'         => 'video/mp4',
                    default       => 'application/octet-stream',
                };
            }

            $type = match (true) {
                str_starts_with($mime, 'image/') => 'image',
                str_starts_with($mime, 'audio/') => 'audio',
                str_starts_with($mime, 'video/') => 'video',
                default                           => 'file',
            };

            $path = $file->store("webchat/{$conversation->id}", 'public');

            if ($path === false) {
                Log::error('Upload store() returned false', ['token' => $token, 'mime' => $mime]);
                return response()->json(['error' => 'Impossible de sauvegarder le fichier. Vérifiez les permissions storage.'], 500);
            }

            // URL servie via l'endpoint dédié — indépendant du symlink storage:link et de APP_URL
            $url = secure_url("api/chat/media/{$token}/" . basename($path));

            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'inbound',
                'type'            => $type,
                'content'         => json_encode([
                    'url'  => $url,
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                ]),
                'status'          => 'delivered',
            ]);

            $conversation->update(['last_message_at' => now()]);

            // ── Réponse IA après upload (vision pour images, texte pour audio/fichier) ──
            $agentMessage = null;
            if ($conversation->ai_active) {
                try {
                    $claude      = app(ClaudeService::class);
                    $aiResponse  = $claude->processUpload($conversation, $type, $path, $mime);

                    // Extraire les quick replies si présents
                    $quickReplies = [];
                    if (preg_match('/\[QUICK_REPLIES:(\[.*?\])\]/s', $aiResponse, $qrMatch)) {
                        $decoded = json_decode($qrMatch[1], true);
                        if (is_array($decoded)) {
                            $quickReplies = array_values(array_slice(
                                array_filter($decoded, fn($r) => is_string($r) && strlen($r) <= 30),
                                0, 3
                            ));
                        }
                    }

                    // Nettoyer les tags pour le stockage
                    $cleanText = trim(preg_replace('/\[QUICK_REPLIES:\[.*?\]\]/s', '', $aiResponse));
                    $cleanText = trim(preg_replace('/\[[A-Z_]+:[^\]]{0,200}\]/', '', $cleanText));

                    if ($cleanText) {
                        $agentMsg = Message::create([
                            'conversation_id' => $conversation->id,
                            'direction'       => 'outbound',
                            'type'            => 'text',
                            'content'         => $cleanText,
                            'status'          => 'sent',
                        ]);
                        $agentMessage = [
                            'id'         => $agentMsg->id,
                            'direction'  => 'outbound',
                            'content'    => $cleanText,
                            'type'       => 'text',
                            'status'     => 'sent',
                            'created_at' => $agentMsg->created_at,
                        ];
                        if (!empty($quickReplies)) {
                            $agentMessage['quick_replies'] = $quickReplies;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('Upload AI response failed', [
                        'token' => $token,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'id'            => $msg->id,
                'type'          => $type,
                'url'           => $url,
                'name'          => $file->getClientOriginalName(),
                'direction'     => 'inbound',
                'status'        => 'delivered',
                'created_at'    => $msg->created_at,
                'agent_message' => $agentMessage,
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Upload failed', [
                'token' => $token,
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
            return response()->json(['error' => 'Erreur lors de l\'envoi du fichier. Veuillez réessayer.'], 500);
        }
    }

    // ── Polling messages ──────────────────────────────────────────────────────

    public function messages(Request $request, string $token): JsonResponse
    {
        $conversation = Conversation::where('session_token', $token)->first();

        if (!$conversation) {
            return response()->json(['error' => 'Session introuvable.'], 404);
        }

        $lastId   = (int) $request->query('last_id', 0);
        $messages = Message::where('conversation_id', $conversation->id)
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'direction', 'content', 'status', 'type', 'created_at']);

        return response()->json([
            'messages'     => $messages,
            'conversation' => [
                'stage'     => $conversation->stage,
                'status'    => $conversation->status,
                'ai_active' => $conversation->ai_active,
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatMessage(Message $msg): array
    {
        return [
            'id'         => $msg->id,
            'direction'  => $msg->direction,
            'content'    => $msg->content,
            'status'     => $msg->status,
            'type'       => $msg->type,
            'created_at' => $msg->created_at,
        ];
    }

    private function handleOrderConfirmed(Conversation $conversation, ClaudeService $claude, array $embeddedData = []): void
    {
        // Priorité 1 : données intégrées dans le tag [ORDER_CONFIRMED:{...}] (nouveau format webchat)
        // Priorité 2 : extraction séparée via Claude (ancien format)
        // Priorité 3 : fallback sur le produit d'intérêt (conversations sans pub Facebook)
        if (!empty($embeddedData['product_id'])) {
            $orderData = $embeddedData;
        } else {
            $orderData = $claude->extractOrderData($conversation);
        }

        if (empty($orderData['product_id'])) {
            $orderData['product_id'] = $conversation->collected_data['interested_product_id'] ?? null;
        }

        if (empty($orderData['product_id'])) {
            Log::warning('WebChat: order data extraction failed — no product_id', [
                'conv'         => $conversation->id,
                'embeddedData' => $embeddedData,
                'orderData'    => $orderData,
            ]);
            return;
        }

        $product = Product::find((int) $orderData['product_id']);

        if (!$product) {
            Log::warning('WebChat: product not found', [
                'conv'       => $conversation->id,
                'product_id' => $orderData['product_id'],
            ]);
            return;
        }

        $order = Order::create([
            'conversation_id'  => $conversation->id,
            'product_id'       => $product->id,
            'customer_name'    => $orderData['customer_name']    ?? $conversation->customer_name    ?? 'Client Webchat',
            'customer_phone'   => $orderData['customer_phone']   ?? $conversation->customer_phone,
            'customer_email'   => $orderData['customer_email']   ?? null,
            'delivery_address' => !empty($orderData['delivery_address']) ? $orderData['delivery_address'] : 'À confirmer',
            'delivery_city'    => !empty($orderData['delivery_city'])    ? $orderData['delivery_city']    : 'Abidjan',
            'total_amount'     => $product->sale_price ?? $product->price ?? 0,
            'status'           => 'pending',
        ]);

        Log::info('WebChat: order created', [
            'order'   => $order->reference,
            'product' => $product->name,
            'conv'    => $conversation->id,
        ]);

        $updateData = ['status' => 'confirmed', 'stage' => 'done'];
        if (!empty($orderData['customer_name']))  $updateData['customer_name']  = $orderData['customer_name'];
        if (!empty($orderData['customer_phone'])) $updateData['customer_phone'] = $orderData['customer_phone'];
        $conversation->update($updateData);

        // La notification peut échouer (agent webchat sans creds WhatsApp) sans bloquer la commande
        try {
            NotifyCoordinators::dispatch($order);
        } catch (\Exception $e) {
            Log::error('WebChat: NotifyCoordinators dispatch failed', [
                'order' => $order->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Catalogue produits de l'agent pour la boutique in-chat ───────────────

    public function catalog(string $token): JsonResponse
    {
        $conversation = Conversation::with('agent')->where('session_token', $token)->first();

        if (!$conversation) {
            return response()->json(['error' => 'Session introuvable.'], 404);
        }

        $agent = $conversation->agent;
        $products = $agent->products()->where('is_available', true)->get();

        if ($products->isEmpty()) {
            $products = Product::where('is_available', true)->get();
        }

        return response()->json([
            'products' => $products->map(fn($p) => [
                'id'        => $p->id,
                'name'      => $p->name,
                'brand'     => $p->brand,
                'price'     => $p->formatted_price,
                'sale_price'=> $p->formatted_sale_price,
                'image_url' => $p->image_url,
                'images'    => $p->images ?: array_filter([$p->image_url]),
                'slug'      => $p->slug,
            ]),
        ]);
    }

    // ── Servir un fichier uploadé via endpoint dédié (pas de symlink requis) ─────

    public function serveMedia(string $token, string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $conversation = Conversation::where('session_token', $token)->firstOrFail();

        // basename() pour éviter toute traversée de répertoire
        $path = 'webchat/' . $conversation->id . '/' . basename($filename);

        if (!Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path);
    }

    private function findAgentForProduct(Product $product): ?WhatsappAgent
    {
        $agent = WhatsappAgent::where('is_active', true)
            ->whereHas('products', fn($q) => $q->where('products.id', $product->id))
            ->first();

        return $agent ?? WhatsappAgent::where('is_active', true)->first();
    }
}