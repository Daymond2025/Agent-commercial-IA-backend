<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
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