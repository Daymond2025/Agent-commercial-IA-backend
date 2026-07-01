<?php

namespace App\Jobs;

use App\Models\Followup;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFollowups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WhatsAppService $whatsapp): void
    {
        $followups = Followup::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->with('conversation.agent')
            ->get();

        foreach ($followups as $followup) {
            $conversation = $followup->conversation;

            if ($conversation->status !== 'active') {
                $followup->update(['status' => 'cancelled']);
                continue;
            }

            if (!$conversation->isWithin24hWindow()) {
                $this->sendTemplateFollowup($whatsapp, $followup);
            } else {
                $this->sendFreeTextFollowup($whatsapp, $followup);
            }
        }
    }

    private function sendFreeTextFollowup(WhatsAppService $whatsapp, Followup $followup): void
    {
        $conversation = $followup->conversation;
        $name = $conversation->customer_name ?? 'cher client';

        $messages = [
            'relance_j0' => "Bonjour {$name} ! 😊 Avez-vous des questions sur nos ordinateurs ? Je suis là pour vous aider à faire le meilleur choix.",
            'relance_j1' => "Bonjour {$name} ! Notre catalogue d'ordinateurs WhatsApp Shop vous attend. Souhaitez-vous que je vous présente nos meilleures offres du moment ?",
            'relance_j3' => "Bonjour {$name} ! 🖥️ Nos stocks sont limités. Voulez-vous finaliser votre commande avant qu'il ne soit trop tard ?",
        ];

        $text = $messages[$followup->template_name] ?? $messages['relance_j0'];

        $whatsapp->sendText($conversation->agent, $conversation->customer_phone, $text);
        $followup->update(['status' => 'sent', 'sent_at' => now()]);

        $this->scheduleNextFollowup($followup, $conversation);
    }

    private function sendTemplateFollowup(WhatsAppService $whatsapp, Followup $followup): void
    {
        $conversation = $followup->conversation;

        $whatsapp->sendTemplate(
            $conversation->agent,
            $conversation->customer_phone,
            $followup->template_name,
            $followup->template_params ?? []
        );

        $followup->update(['status' => 'sent', 'sent_at' => now()]);
        $this->scheduleNextFollowup($followup, $conversation);
    }

    private function scheduleNextFollowup(Followup $current, $conversation): void
    {
        $nextSchedules = [
            'relance_j0' => ['relance_j1', now()->addDay()],
            'relance_j1' => ['relance_j3', now()->addDays(3)],
            'relance_j3' => ['relance_j7', now()->addDays(7)],
        ];

        if (isset($nextSchedules[$current->template_name])) {
            [$nextTemplate, $nextTime] = $nextSchedules[$current->template_name];
            Followup::create([
                'conversation_id' => $conversation->id,
                'scheduled_at' => $nextTime,
                'template_name' => $nextTemplate,
                'template_params' => [$conversation->customer_name ?? 'cher client'],
                'status' => 'pending',
            ]);
        }
    }
}