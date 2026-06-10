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
        $query = Conversation::with(['agent', 'order'])
            ->withCount('messages')
            ->orderByDesc('last_message_at');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->agent_id) {
            $query->where('whatsapp_agent_id', $request->agent_id);
        }

        return response()->json($query->paginate(20));
    }

    public function show(Conversation $conversation): JsonResponse
    {
        return response()->json(
            $conversation->load(['agent', 'messages', 'order.product', 'followups'])
        );
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'active' => Conversation::where('status', 'active')->count(),
            'confirmed' => Conversation::where('status', 'confirmed')->count(),
            'abandoned' => Conversation::where('status', 'abandoned')->count(),
            'total_today' => Conversation::whereDate('created_at', today())->count(),
        ]);
    }
}