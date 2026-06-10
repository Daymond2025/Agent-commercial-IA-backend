<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Product::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'brand' => 'nullable|string|max:100',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'currency' => 'string|max:10',
            'specs' => 'nullable|array',
            'image_url' => 'nullable|url',
            'is_available' => 'boolean',
            'stock' => 'integer|min:0',
        ]);

        $product = Product::create($validated);
        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'brand' => 'nullable|string|max:100',
            'description' => 'string',
            'price' => 'numeric|min:0',
            'currency' => 'string|max:10',
            'specs' => 'nullable|array',
            'image_url' => 'nullable|url',
            'is_available' => 'boolean',
            'stock' => 'integer|min:0',
        ]);

        $product->update($validated);
        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();
        return response()->json(['message' => 'Produit supprimé']);
    }
}