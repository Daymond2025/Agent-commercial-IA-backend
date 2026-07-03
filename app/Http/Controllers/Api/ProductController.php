<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    private const VALIDATION_MESSAGES = [
        'images.*.image' => "L'un des fichiers envoyés n'est pas une image valide.",
        'images.*.max'   => 'Chaque image ne doit pas dépasser 4 Mo.',
        'images.max'     => 'Maximum 10 images par produit.',
    ];

    public function index(): JsonResponse
    {
        return response()->json(Product::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'brand'           => 'nullable|string|max:100',
            'description'     => 'required|string',
            'price'           => 'required|numeric|min:0',
            'sale_price'      => 'nullable|numeric|min:0|lt:price',
            'currency'        => 'nullable|string|max:10',
            'specs'           => 'nullable',
            'images'          => 'nullable|array|max:10',
            'images.*'        => 'image|max:4096',
            'existing_images' => 'nullable|string',
            'is_available'    => 'nullable|boolean',
            'stock'           => 'nullable|integer|min:0',
        ], self::VALIDATION_MESSAGES);

        if (isset($validated['specs']) && is_string($validated['specs'])) {
            $validated['specs'] = json_decode($validated['specs'], true);
        }

        $kept    = json_decode($validated['existing_images'] ?? '[]', true) ?? [];
        $gallery = $this->buildGallery($request, $validated, keptExisting: $kept);

        $validated['images']    = $gallery;
        $validated['image_url'] = $gallery[0] ?? null;
        unset($validated['existing_images']);

        $product = Product::create($validated);

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'brand'           => 'nullable|string|max:100',
            'description'     => 'sometimes|string',
            'price'           => 'sometimes|numeric|min:0',
            'sale_price'      => 'nullable|numeric|min:0',
            'currency'        => 'nullable|string|max:10',
            'specs'           => 'nullable',
            'images'          => 'nullable|array|max:10',
            'images.*'        => 'image|max:4096',
            'existing_images' => 'nullable|string',
            'is_available'    => 'nullable|boolean',
            'stock'           => 'nullable|integer|min:0',
        ], self::VALIDATION_MESSAGES);

        if (isset($validated['specs']) && is_string($validated['specs'])) {
            $validated['specs'] = json_decode($validated['specs'], true);
        }

        // La galerie n'est reconstruite que si le front l'a explicitement envoyée
        // (présence de "existing_images"), pour ne pas l'effacer sur une simple
        // mise à jour de prix/stock qui ne touche pas aux images.
        if ($request->has('existing_images')) {
            $kept = json_decode($validated['existing_images'] ?? '[]', true) ?? [];

            foreach (array_diff($product->images ?? [], $kept) as $removedUrl) {
                $this->deleteIfInternal($removedUrl);
            }

            $gallery                = $this->buildGallery($request, $validated, keptExisting: $kept);
            $validated['images']    = $gallery;
            $validated['image_url'] = $gallery[0] ?? null;
        }

        unset($validated['existing_images']);

        $product->update($validated);

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        foreach ($product->images ?? [] as $url) {
            $this->deleteIfInternal($url);
        }

        $product->delete();

        return response()->json(['message' => 'Produit supprimé']);
    }

    /**
     * Fusionne les images conservées (URLs déjà existantes ou externes) avec
     * les nouveaux fichiers uploadés dans cette requête.
     */
    private function buildGallery(Request $request, array $validated, array $keptExisting): array
    {
        $newUrls = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $path      = $file->store('products/images', 'public');
                $newUrls[] = $this->absoluteStorageUrl($request, $path);
            }
        }

        return array_values(array_merge($keptExisting, $newUrls));
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
}
