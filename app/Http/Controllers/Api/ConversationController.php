<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Conversation::with([
                'agent:id,name,avatar_url,persona',
                'latestMessage', // latestOfMany() incompatible avec la sélection de colonnes
            ])
            ->withCount('messages')
            ->orderByDesc('last_message_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('agent_id')) {
            $query->where('whatsapp_agent_id', $request->agent_id);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('customer_phone', 'like', $term)
                  ->orWhere('customer_name', 'like', $term);
            });
        }

        return response()->json($query->paginate(30));
    }

    public function show(Conversation $conversation): JsonResponse
    {
        return response()->json(
            $conversation->load(['agent', 'messages', 'order.product', 'followups'])
        );
    }

    /**
     * Envoyer un message depuis le dashboard (prise de main humaine).
     * Commandes spéciales : ".." = pause IA, "..." = reprendre IA
     */
    public function sendMessage(Request $request, Conversation $conversation, WhatsAppService $whatsapp): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:4096']);
        $msg = trim($request->message);

        // Commandes de contrôle IA
        if ($msg === '..') {
            $conversation->update(['ai_active' => false, 'ai_paused_at' => now()]);
            return response()->json(['ai_paused' => true, 'message' => 'IA mise en pause pour cette conversation.']);
        }
        if ($msg === '...') {
            $conversation->update(['ai_active' => true, 'ai_paused_at' => null]);
            return response()->json(['ai_active' => true, 'message' => 'IA réactivée pour cette conversation.']);
        }

        if (!$conversation->isWithin24hWindow()) {
            return response()->json(['error' => 'Fenêtre 24h expirée.'], 422);
        }

        $whatsapp->sendText($conversation->agent, $conversation->customer_phone, $msg);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'text',
            'content'         => $msg,
            'status'          => 'sent',
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json($message, 201);
    }

    /**
     * Basculer l'état de l'IA pour une conversation (toggle depuis le dashboard).
     */
    public function toggleAI(Conversation $conversation): JsonResponse
    {
        $newState = !$conversation->ai_active;
        $conversation->update([
            'ai_active'    => $newState,
            'ai_paused_at' => $newState ? null : now(),
        ]);

        return response()->json([
            'ai_active' => $newState,
            'message'   => $newState ? 'IA réactivée.' : 'IA mise en pause.',
        ]);
    }

    public function stats(): JsonResponse
    {
        $todayStart     = now()->startOfDay();
        $yesterdayStart = now()->subDay()->startOfDay();
        $yesterdayEnd   = now()->subDay()->endOfDay();

        $totalToday     = Conversation::where('created_at', '>=', $todayStart)->count();
        $totalYesterday = Conversation::whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])->count();

        $leadsToRelance = Conversation::where(function ($q) {
            $q->where('status', 'abandoned')
              ->orWhere(function ($inner) {
                  $inner->where('status', 'active')
                        ->where('last_message_at', '<', now()->subHour());
              });
        })->count();

        $leadsThisWeek = Conversation::where('created_at', '>=', now()->startOfWeek())->count();

        $trendConvs = $totalYesterday > 0
            ? (int) round((($totalToday - $totalYesterday) / $totalYesterday) * 100)
            : ($totalToday > 0 ? 100 : 0);

        return response()->json([
            'active'            => Conversation::where('status', 'active')->count(),
            'confirmed'         => Conversation::where('status', 'confirmed')->count(),
            'abandoned'         => Conversation::where('status', 'abandoned')->count(),
            'total_today'       => $totalToday,
            'trend_today'       => $trendConvs,
            'leads_to_relance'  => $leadsToRelance,
            'leads_this_week'   => $leadsThisWeek,
        ]);
    }
}