<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Product::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'brand'        => 'nullable|string|max:100',
            'description'  => 'required|string',
            'price'        => 'required|numeric|min:0',
            'sale_price'   => 'nullable|numeric|min:0|lt:price',
            'currency'     => 'nullable|string|max:10',
            'specs'        => 'nullable',
            'image_url'    => 'nullable|url',
            'image'        => 'nullable|image|max:4096',
            'is_available' => 'nullable|boolean',
            'stock'        => 'nullable|integer|min:0',
        ]);

        // specs peut arriver comme string JSON (multipart)
        if (isset($validated['specs']) && is_string($validated['specs'])) {
            $validated['specs'] = json_decode($validated['specs'], true);
        }

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products/images', 'public');
            $validated['image_url'] = $this->absoluteStorageUrl($request, $path);
        }

        unset($validated['image']);

        $product = Product::create($validated);

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'brand'        => 'nullable|string|max:100',
            'description'  => 'sometimes|string',
            'price'        => 'sometimes|numeric|min:0',
            'sale_price'   => 'nullable|numeric|min:0',
            'currency'     => 'nullable|string|max:10',
            'specs'        => 'nullable',
            'image_url'    => 'nullable|url',
            'image'        => 'nullable|image|max:4096',
            'is_available' => 'nullable|boolean',
            'stock'        => 'nullable|integer|min:0',
        ]);

        if (isset($validated['specs']) && is_string($validated['specs'])) {
            $validated['specs'] = json_decode($validated['specs'], true);
        }

        if ($request->hasFile('image')) {
            // Supprime l'ancienne image uploadée si elle existait
            if ($product->image_url && str_starts_with($product->image_url, '/storage/')) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $product->image_url));
            }
            $path = $request->file('image')->store('products/images', 'public');
            $validated['image_url'] = $this->absoluteStorageUrl($request, $path);
        }

        unset($validated['image']);

        $product->update($validated);

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($product->image_url && str_starts_with($product->image_url, '/storage/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $product->image_url));
        }

        $product->delete();

        return response()->json(['message' => 'Produit supprimé']);
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
}