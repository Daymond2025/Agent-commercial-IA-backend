<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(WhatsappAgent::withCount('conversations')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'phone_number' => 'required|string|unique:whatsapp_agents',
            'phone_number_id' => 'required|string|unique:whatsapp_agents',
            'access_token' => 'required|string',
            'waba_id' => 'required|string',
            'persona' => 'nullable|array',
            'persona.name' => 'string',
        ]);

        $agent = WhatsappAgent::create($validated);
        return response()->json($agent, 201);
    }

    public function update(Request $request, WhatsappAgent $agent): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:100',
            'access_token' => 'string',
            'is_active' => 'boolean',
            'persona' => 'nullable|array',
        ]);

        $agent->update($validated);
        return response()->json($agent);
    }

    public function destroy(WhatsappAgent $agent): JsonResponse
    {
        $agent->update(['is_active' => false]);
        return response()->json(['message' => 'Agent désactivé']);
    }
}