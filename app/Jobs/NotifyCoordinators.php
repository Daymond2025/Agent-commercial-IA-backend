<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use App\Models\WhatsappAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyCoordinators implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private Order $order) {}

    public function handle(WhatsAppService $whatsapp): void
    {
        $this->order->load(['product', 'conversation.agent']);
        $agent = $this->order->conversation->agent;

        $coordinators = User::where('role', 'coordinator')
            ->where('is_active', true)
            ->whereNotNull('whatsapp_phone')
            ->get();

        if ($coordinators->isEmpty()) {
            Log::warning('No active coordinators to notify', ['order' => $this->order->reference]);
            return;
        }

        // Assigner le premier coordinateur disponible
        $coordinator = $coordinators->first();
        $this->order->update(['assigned_coordinator_id' => $coordinator->id]);

        // Si l'agent n'a pas de credentials WhatsApp (ex. agent webchat), on skip l'envoi WA
        if (empty($agent->phone_number_id) || empty($agent->access_token)) {
            Log::info('NotifyCoordinators: agent sans credentials WhatsApp, notification WA ignorée', [
                'order' => $this->order->reference,
                'agent' => $agent->id,
            ]);
            return;
        }

        $message = $this->buildNotificationMessage();

        foreach ($coordinators as $coord) {
            try {
                $whatsapp->sendText($agent, $coord->whatsapp_phone, $message);
            } catch (\Exception $e) {
                Log::error('NotifyCoordinators: sendText failed', [
                    'coordinator' => $coord->name,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        Log::info('Coordinators notified via WhatsApp', [
            'order'        => $this->order->reference,
            'coordinators' => $coordinators->pluck('name'),
        ]);
    }

    private function buildNotificationMessage(): string
    {
        $o = $this->order;
        $product = $o->product;

        return "🛒 *NOUVELLE COMMANDE WHATSAPP SHOP*\n\n"
            . "📋 Réf: *{$o->reference}*\n"
            . "👤 Client: {$o->customer_name}\n"
            . "📱 Tél: {$o->customer_phone}\n"
            . ($o->customer_email ? "📧 Email: {$o->customer_email}\n" : "")
            . "\n🖥️ Produit: *{$product->name}*\n"
            . "💰 Montant: *" . number_format($o->total_amount, 0, ',', ' ') . " {$o->currency}*\n"
            . "\n📍 Livraison:\n"
            . "{$o->delivery_address}\n"
            . "{$o->delivery_city}\n"
            . "\n⏰ " . now()->format('d/m/Y H:i') . "\n"
            . "\n👉 Accédez au dashboard pour traiter cette commande.";
    }
}