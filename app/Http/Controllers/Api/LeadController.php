<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Followup;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    /**
     * Leads à relancer : conversations abandonnées OU actives depuis + d'1h sans conversion.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Conversation::with(['agent:id,name,avatar_url,persona,phone_number'])
            ->withCount('followups')
            ->where(function ($q) {
                $q->where('status', 'abandoned')
                  ->orWhere(function ($inner) {
                      $inner->where('status', 'active')
                            ->where('last_message_at', '<', now()->subHour());
                  });
            });

        if ($request->filled('agent_id')) {
            $query->where('whatsapp_agent_id', $request->agent_id);
        }

        if ($request->filled('stage')) {
            $query->where('stage', $request->stage);
        }

        // Leads les plus chauds en premier (étape la plus avancée)
        $query->orderByRaw("CASE
                WHEN stage = 'confirmation'      THEN 1
                WHEN stage = 'order_summary'     THEN 2
                WHEN stage = 'customer_info'     THEN 3
                WHEN stage = 'product_selection' THEN 4
                WHEN stage = 'greeting'          THEN 5
                ELSE 6 END")
              ->orderByDesc('last_message_at');

        return response()->json($query->paginate(25));
    }

    /**
     * Compteur léger pour le badge sidebar.
     */
    public function count(): JsonResponse
    {
        $count = Conversation::where(function ($q) {
            $q->where('status', 'abandoned')
              ->orWhere(function ($inner) {
                  $inner->where('status', 'active')
                        ->where('last_message_at', '<', now()->subHour());
              });
        })->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Envoyer manuellement un message de relance via WhatsApp.
     */
    public function relance(Request $request, Conversation $conversation, WhatsAppService $whatsapp): JsonResponse
    {
        if ($conversation->status === 'confirmed') {
            return response()->json(['error' => 'Cette conversation est déjà confirmée.'], 422);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        if (!$conversation->isWithin24hWindow()) {
            return response()->json([
                'error'          => 'La fenêtre de messagerie de 24h est expirée. Ce client doit vous écrire en premier pour rouvrir la fenêtre.',
                'window_expired' => true,
            ], 422);
        }

        $whatsapp->sendText(
            $conversation->agent,
            $conversation->customer_phone,
            $validated['message']
        );

        Followup::create([
            'conversation_id' => $conversation->id,
            'scheduled_at'    => now(),
            'template_name'   => 'manual_relance',
            'template_params' => [$validated['message']],
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Message de relance envoyé avec succès.']);
    }
}