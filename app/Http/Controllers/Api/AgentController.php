<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        $agents = WhatsappAgent::withCount('conversations')
            ->with('products:id,name,brand')
            ->get();

        return response()->json($agents);
    }

    public function show(WhatsappAgent $agent): JsonResponse
    {
        return response()->json(
            $agent->loadCount('conversations')->load('products:id,name,brand,price,sale_price,image_url')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:100',
            'phone_number'   => 'required|string|unique:whatsapp_agents',
            'phone_number_id'=> 'required|string|unique:whatsapp_agents',
            'access_token'   => 'required|string',
            'waba_id'        => 'required|string',
            'persona'        => 'nullable',
            'instructions'   => 'nullable|string',
            'knowledge_base' => 'nullable|string',
            'website_url'    => 'nullable|url',
            'avatar'         => 'nullable|image|max:2048',
        ]);

        // persona peut arriver comme string JSON (multipart)
        if (isset($validated['persona']) && is_string($validated['persona'])) {
            $validated['persona'] = json_decode($validated['persona'], true);
        }

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('agents/avatars', 'public');
            $validated['avatar_url'] = Storage::url($path);
        }

        unset($validated['avatar']);

        $agent = WhatsappAgent::create($validated);

        return response()->json($agent->load('products:id,name,brand'), 201);
    }

    public function update(Request $request, WhatsappAgent $agent): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'sometimes|string|max:100',
            'access_token'   => 'sometimes|string',
            'is_active'      => 'sometimes|boolean',
            'persona'        => 'nullable',
            'instructions'   => 'nullable|string',
            'knowledge_base' => 'nullable|string',
            'website_url'    => 'nullable|url',
            'avatar'         => 'nullable|image|max:2048',
        ]);

        if (isset($validated['persona']) && is_string($validated['persona'])) {
            $validated['persona'] = json_decode($validated['persona'], true);
        }

        if ($request->hasFile('avatar')) {
            // Supprime l'ancienne si c'était un fichier uploadé
            if ($agent->avatar_url && str_starts_with($agent->avatar_url, '/storage/')) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $agent->avatar_url));
            }
            $path = $request->file('avatar')->store('agents/avatars', 'public');
            $validated['avatar_url'] = Storage::url($path);
        }

        unset($validated['avatar']);

        $agent->update($validated);

        return response()->json($agent->load('products:id,name,brand'));
    }

    public function syncProducts(Request $request, WhatsappAgent $agent): JsonResponse
    {
        $validated = $request->validate([
            'product_ids'   => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $agent->products()->sync($validated['product_ids']);

        return response()->json($agent->load('products:id,name,brand,price,sale_price,image_url'));
    }

    public function stats(): JsonResponse
    {
        $agents = WhatsappAgent::withCount([
            'conversations as leads_count',
            'conversations as orders_count' => fn($q) =>
                $q->whereHas('order', fn($oq) =>
                    $oq->whereIn('status', ['confirmed', 'processing', 'shipped', 'delivered'])
                ),
        ])->get(['id', 'name', 'avatar_url', 'persona', 'is_active', 'phone_number']);

        return response()->json($agents);
    }

    public function destroy(WhatsappAgent $agent): JsonResponse
    {
        $agent->update(['is_active' => false]);
        return response()->json(['message' => 'Agent désactivé']);
    }
}