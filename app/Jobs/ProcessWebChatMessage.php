<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Services\AI\ClaudeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWebChatMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        private int    $conversationId,
        private string $userMessage
    ) {}

    public function handle(ClaudeService $claude): void
    {
        $conversation = Conversation::with('agent')->findOrFail($this->conversationId);

        if (!$conversation->ai_active) {
            Log::info('WebChat: AI paused — no response generated', ['conv' => $this->conversationId]);
            return;
        }

        try {
            $aiResponse     = $claude->processMessage($conversation, $this->userMessage);
            $orderConfirmed = str_contains($aiResponse, '[ORDER_CONFIRMED]');
            $cleanResponse  = trim(str_replace('[ORDER_CONFIRMED]', '', $aiResponse));

            Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'text',
                'content'         => $cleanResponse,
                'status'          => 'sent',
            ]);

            if ($orderConfirmed) {
                $this->handleOrderConfirmed($conversation, $claude);
            }

        } catch (\Exception $e) {
            Log::error('WebChat: AI processing failed', [
                'conversation' => $this->conversationId,
                'error'        => $e->getMessage(),
            ]);

            // Message d'erreur visible par le client
            Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'text',
                'content'         => "Désolé, je rencontre un problème technique. Pouvez-vous réessayer dans quelques instants ?",
                'status'          => 'sent',
            ]);
        }
    }

    private function handleOrderConfirmed(Conversation $conversation, ClaudeService $claude): void
    {
        $orderData = $claude->extractOrderData($conversation);

        if (empty($orderData) || !isset($orderData['product_id'])) {
            Log::warning('WebChat: could not extract order data', ['conversation' => $conversation->id]);
            return;
        }

        $product = Product::find($orderData['product_id']);

        $order = Order::create([
            'conversation_id'  => $conversation->id,
            'product_id'       => $orderData['product_id'],
            'customer_name'    => $orderData['customer_name']    ?? $conversation->customer_name,
            'customer_phone'   => $orderData['customer_phone']   ?? $conversation->customer_phone,
            'customer_email'   => $orderData['customer_email']   ?? null,
            'delivery_address' => $orderData['delivery_address'] ?? '',
            'delivery_city'    => $orderData['delivery_city']    ?? '',
            'total_amount'     => $product?->sale_price ?? $product?->price ?? 0,
            'status'           => 'pending',
        ]);

        // Mettre à jour les infos client récoltées par l'IA
        $updateData = ['status' => 'confirmed', 'stage' => 'done'];
        if (!empty($orderData['customer_name']))  $updateData['customer_name']  = $orderData['customer_name'];
        if (!empty($orderData['customer_phone'])) $updateData['customer_phone'] = $orderData['customer_phone'];
        $conversation->update($updateData);

        // Notifier les coordinateurs (même flow que WhatsApp)
        NotifyCoordinators::dispatch($order);

        Log::info('WebChat: order confirmed', [
            'conversation' => $conversation->id,
            'order'        => $order->id,
        ]);
    }
}