<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentDocument;
use App\Models\WhatsappAgent;
use App\Services\AI\ClaudeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;

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
            'support_phone'  => 'nullable|string|max:20',
            'persona'        => 'nullable',
            'instructions'   => 'nullable|string',
            'knowledge_base' => 'nullable|string',
            'website_url'    => 'nullable|url',
            'avatar'         => 'nullable|image|max:4096',
        ], [
            'avatar.image' => "Le fichier envoyé n'est pas une image valide.",
            'avatar.max'   => "L'image de profil ne doit pas dépasser 4 Mo.",
        ]);

        // persona peut arriver comme string JSON (multipart)
        if (isset($validated['persona']) && is_string($validated['persona'])) {
            $validated['persona'] = json_decode($validated['persona'], true);
        }

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('agents/avatars', 'public');
            $validated['avatar_url'] = $this->absoluteStorageUrl($request, $path);
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
            'support_phone'  => 'nullable|string|max:20',
            'persona'        => 'nullable',
            'instructions'   => 'nullable|string',
            'knowledge_base' => 'nullable|string',
            'website_url'    => 'nullable|url',
            'avatar'         => 'nullable|image|max:4096',
        ], [
            'avatar.image' => "Le fichier envoyé n'est pas une image valide.",
            'avatar.max'   => "L'image de profil ne doit pas dépasser 4 Mo.",
        ]);

        if (isset($validated['persona']) && is_string($validated['persona'])) {
            $validated['persona'] = json_decode($validated['persona'], true);
        }

        if ($request->hasFile('avatar')) {
            // Supprime l'ancienne si c'était un fichier uploadé
            if ($agent->avatar_url) {
                $this->deleteIfInternal($agent->avatar_url);
            }
            $path = $request->file('avatar')->store('agents/avatars', 'public');
            $validated['avatar_url'] = $this->absoluteStorageUrl($request, $path);
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

    /**
     * Upload d'un document (PDF ou TXT) pour enrichir la base de connaissances.
     */
    public function uploadKnowledge(Request $request, WhatsappAgent $agent): JsonResponse
    {
        $request->validate([
            'documents'   => 'required|array|min:1|max:20',
            'documents.*' => 'file|mimes:pdf,txt,csv|max:10240', // 10 Mo par fichier
        ]);

        $results   = [];
        $separator = "\n\n---\n\n";
        $currentKb = $agent->knowledge_base ?? '';

        foreach ($request->file('documents') as $file) {
            $mime = $file->getMimeType();
            $path = $file->store("agents/{$agent->id}/documents", 'public');

            $extracted = match (true) {
                str_contains($mime, 'pdf')  => $this->extractPdfText($file->getRealPath()),
                default                      => file_get_contents($file->getRealPath()),
            };
            $extracted = trim($extracted);

            AgentDocument::create([
                'whatsapp_agent_id' => $agent->id,
                'original_name'     => $file->getClientOriginalName(),
                'file_path'         => $path,
                'mime_type'         => $mime,
                'extracted_text'    => $extracted,
            ]);

            $docHeader  = "SOURCE : " . $file->getClientOriginalName();
            $currentKb  = trim($currentKb . $separator . $docHeader . "\n" . $extracted);

            $results[] = [
                'name'       => $file->getClientOriginalName(),
                'chars'      => strlen($extracted),
                'mime'       => $mime,
            ];
        }

        $agent->update(['knowledge_base' => $currentKb]);

        return response()->json([
            'message' => count($results) . ' document(s) importé(s) avec succès.',
            'files'   => $results,
        ], 201);
    }

    /**
     * Liste des documents uploadés pour un agent.
     */
    public function documents(WhatsappAgent $agent): JsonResponse
    {
        return response()->json(
            $agent->hasMany(AgentDocument::class, 'whatsapp_agent_id')
                  ->orderByDesc('created_at')
                  ->get(['id', 'original_name', 'mime_type', 'created_at'])
        );
    }

    /**
     * Auto-formation immédiate : Claude analyse les conversations réussies
     * et génère des insights à ajouter à la knowledge_base de l'agent.
     */
    public function train(WhatsappAgent $agent, ClaudeService $claude): JsonResponse
    {
        $insights = $claude->trainAgent($agent);

        if (!$insights) {
            return response()->json(['message' => 'Pas assez de données pour former l\'agent.'], 422);
        }

        $separator = "\n\n---\n\n";
        $header    = "AUTO-FORMATION (" . now()->format('d/m/Y H:i') . ") :";
        $agent->update([
            'knowledge_base' => trim(($agent->knowledge_base ?? '') . $separator . $header . "\n" . $insights),
        ]);

        return response()->json([
            'message' => 'Agent formé avec succès.',
            'insights_preview' => substr($insights, 0, 300) . '...',
        ]);
    }

    public function destroy(WhatsappAgent $agent): JsonResponse
    {
        $agent->update(['is_active' => false]);
        return response()->json(['message' => 'Agent désactivé']);
    }

    /**
     * Storage::url() dépend de APP_URL et peut renvoyer un chemin relatif
     * si mal configuré côté serveur — on force une URL absolue basée sur
     * l'hôte réel de la requête pour rester correct dans tous les cas.
     */
    private function absoluteStorageUrl(Request $request, string $path): string
    {
        $url = Storage::url($path);
        return str_starts_with($url, 'http') ? $url : $request->getSchemeAndHttpHost() . $url;
    }

    /**
     * Supprime le fichier du disque public si l'URL pointe vers du stockage
     * interne (absolue ou relative) ; ignore silencieusement les URLs externes.
     */
    private function deleteIfInternal(string $url): void
    {
        if (!str_contains($url, '/storage/')) {
            return;
        }

        Storage::disk('public')->delete(Str::after($url, '/storage/'));
    }

    private function extractPdfText(string $filePath): string
    {
        // La plupart des PDF réels compressent leurs flux de contenu (FlateDecode) :
        // un parsing regex sur les octets bruts ne peut pas les lire. On utilise
        // smalot/pdfparser, qui décompresse et décode correctement le texte.
        try {
            $parser = new PdfParser();
            $text   = trim($parser->parseFile($filePath)->getText());

            return $text !== '' ? $text : '[PDF non lisible — aucun texte extractible (probablement un scan/image)]';
        } catch (\Throwable $e) {
            Log::warning('PDF extraction failed', ['file' => $filePath, 'error' => $e->getMessage()]);
            return '[PDF non lisible — erreur lors de l\'extraction]';
        }
    }
}