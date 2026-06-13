<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Followup;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsappAgent;
use App\Services\AI\ClaudeService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        private int   $agentId,
        private array $message,
        private array $contact
    ) {}

    public function handle(ClaudeService $claude, WhatsAppService $whatsapp): void
    {
        $agent         = WhatsappAgent::findOrFail($this->agentId);
        $customerPhone = $this->message['from'];
        $messageId     = $this->message['id'];

        $textContent = $this->extractTextContent();
        if (!$textContent) return;

        // ── Commandes staff depuis WhatsApp personnel ─────────────────────────
        // Si le numéro expéditeur correspond à un coordinateur/admin enregistré,
        // on traite comme commande de contrôle, pas comme message client.
        $staffUser = User::where('whatsapp_phone', $customerPhone)
            ->whereIn('role', ['admin', 'coordinator'])
            ->first();

        if ($staffUser) {
            $this->handleStaffCommand($textContent, $staffUser, $agent, $whatsapp);
            return;
        }

        // ── Message client normal ─────────────────────────────────────────────
        $conversation = $this->getOrCreateConversation($agent, $customerPhone);

        Message::create([
            'conversation_id'     => $conversation->id,
            'direction'           => 'inbound',
            'type'                => $this->message['type'] ?? 'text',
            'content'             => $textContent,
            'whatsapp_message_id' => $messageId,
            'status'              => 'delivered',
        ]);

        $conversation->update([
            'last_message_at'  => now(),
            'window_expires_at'=> now()->addHours(24),
            'customer_name'    => $this->contact['profile']['name'] ?? $conversation->customer_name,
        ]);

        $whatsapp->markAsRead($agent, $messageId);

        // ── L'IA est-elle active pour cette conversation ? ────────────────────
        $conversation->refresh();
        if (!$conversation->ai_active) {
            Log::info('AI paused — message saved, no response', ['conv' => $conversation->id]);
            return;
        }

        $aiResponse     = $claude->processMessage($conversation, $textContent);
        $orderConfirmed = str_contains($aiResponse, '[ORDER_CONFIRMED]');
        $cleanResponse  = str_replace('[ORDER_CONFIRMED]', '', $aiResponse);

        $whatsapp->sendText($agent, $customerPhone, trim($cleanResponse));

        Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'text',
            'content'         => trim($cleanResponse),
            'status'          => 'sent',
        ]);

        if ($orderConfirmed) {
            $this->handleOrderConfirmed($conversation, $agent, $claude, $whatsapp);
        }

        $this->scheduleFollowupIfNeeded($conversation);
    }

    // ── Commandes staff ───────────────────────────────────────────────────────

    private function handleStaffCommand(string $text, User $staff, WhatsappAgent $agent, WhatsAppService $whatsapp): void
    {
        $text    = trim($text);
        $pattern = '/^(\.\.|\.\.\.)\s*(\+?\d+)?$/';

        // Commande "?" → liste des conversations actives de cet agent
        if ($text === '?') {
            $convs = Conversation::where('whatsapp_agent_id', $agent->id)
                ->whereIn('status', ['active', 'pending_confirmation'])
                ->orderByDesc('last_message_at')
                ->limit(5)
                ->get(['customer_name', 'customer_phone', 'stage', 'ai_active']);

            if ($convs->isEmpty()) {
                $msg = "Aucune conversation active pour cet agent.";
            } else {
                $lines = $convs->map(fn($c) =>
                    "• {$c->customer_name} ({$c->customer_phone}) — {$c->stage} — IA: " . ($c->ai_active ? '✅' : '⏸️')
                )->implode("\n");
                $msg = "Conversations actives :\n{$lines}\n\nPour mettre en pause : '.. +numéro'\nPour relancer : '... +numéro'";
            }

            $whatsapp->sendText($agent, $staff->whatsapp_phone, $msg);
            return;
        }

        if (!preg_match($pattern, $text, $matches)) {
            $help = "Commandes disponibles :\n.. +numéro → pause l'IA\n... +numéro → reprend l'IA\n? → liste les conversations actives";
            $whatsapp->sendText($agent, $staff->whatsapp_phone, $help);
            return;
        }

        $command     = $matches[1];       // '..' ou '...'
        $targetPhone = $matches[2] ?? null;

        if (!$targetPhone) {
            $whatsapp->sendText($agent, $staff->whatsapp_phone,
                "Précisez le numéro du client : '{$command} +22507xxxxxxxx'"
            );
            return;
        }

        $conversation = Conversation::where('whatsapp_agent_id', $agent->id)
            ->where('customer_phone', $targetPhone)
            ->orderByDesc('last_message_at')
            ->first();

        if (!$conversation) {
            $whatsapp->sendText($agent, $staff->whatsapp_phone,
                "Aucune conversation trouvée pour {$targetPhone}."
            );
            return;
        }

        if ($command === '..') {
            $conversation->update(['ai_active' => false, 'ai_paused_at' => now()]);
            $whatsapp->sendText($agent, $staff->whatsapp_phone,
                "⏸️ IA mise en pause pour {$conversation->customer_name} ({$targetPhone}).\nVous pouvez répondre manuellement. Envoyez '... {$targetPhone}' pour relancer l'IA."
            );
            Log::info('AI paused via WhatsApp staff command', [
                'staff' => $staff->email, 'conv' => $conversation->id,
            ]);
        } else {
            $conversation->update(['ai_active' => true, 'ai_paused_at' => null]);
            $whatsapp->sendText($agent, $staff->whatsapp_phone,
                "✅ IA relancée pour {$conversation->customer_name} ({$targetPhone})."
            );
            Log::info('AI resumed via WhatsApp staff command', [
                'staff' => $staff->email, 'conv' => $conversation->id,
            ]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractTextContent(): ?string
    {
        $type = $this->message['type'] ?? 'text';
        return match ($type) {
            'text'        => $this->message['text']['body'] ?? null,
            'interactive' => $this->message['interactive']['button_reply']['title']
                ?? $this->message['interactive']['list_reply']['title']
                ?? null,
            default       => null,
        };
    }

    private function getOrCreateConversation(WhatsappAgent $agent, string $phone): Conversation
    {
        return Conversation::firstOrCreate(
            ['whatsapp_agent_id' => $agent->id, 'customer_phone' => $phone, 'status' => 'active'],
            ['stage' => 'greeting', 'ai_active' => true]
        );
    }

    private function handleOrderConfirmed(
        Conversation  $conversation,
        WhatsappAgent $agent,
        ClaudeService $claude,
        WhatsAppService $whatsapp
    ): void {
        $orderData = $claude->extractOrderData($conversation);

        if (empty($orderData) || !isset($orderData['product_id'])) {
            Log::warning('Could not extract order data', ['conversation' => $conversation->id]);
            return;
        }

        $order = Order::create([
            'conversation_id' => $conversation->id,
            'product_id'      => $orderData['product_id'],
            'customer_name'   => $orderData['customer_name'] ?? $conversation->customer_name,
            'customer_phone'  => $conversation->customer_phone,
            'customer_email'  => $orderData['customer_email'] ?? null,
            'delivery_address'=> $orderData['delivery_address'] ?? '',
            'delivery_city'   => $orderData['delivery_city'] ?? '',
            'total_amount'    => \App\Models\Product::find($orderData['product_id'])?->price ?? 0,
            'status'          => 'pending',
        ]);

        $conversation->update(['status' => 'confirmed', 'stage' => 'done']);

        NotifyCoordinators::dispatch($order);
    }

    private function scheduleFollowupIfNeeded(Conversation $conversation): void
    {
        if ($conversation->status !== 'active') return;

        $existing = Followup::where('conversation_id', $conversation->id)
            ->where('status', 'pending')
            ->exists();

        if (!$existing) {
            Followup::create([
                'conversation_id' => $conversation->id,
                'scheduled_at'    => now()->addHours(4),
                'template_name'   => 'relance_j0',
                'template_params' => [$conversation->customer_name ?? 'cher client'],
                'status'          => 'pending',
            ]);
        }
    }
}