<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RelanceTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelanceTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            RelanceTemplate::with('creator:id,name')->orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'message'      => 'required|string|max:1000',
            'stage_target' => 'nullable|in:greeting,product_selection,customer_info,order_summary,confirmation',
            'is_active'    => 'boolean',
        ]);

        $template = RelanceTemplate::create([
            ...$validated,
            'created_by' => auth()->id(),
        ]);

        return response()->json($template->load('creator:id,name'), 201);
    }

    public function update(Request $request, RelanceTemplate $relanceTemplate): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'message'      => 'sometimes|string|max:1000',
            'stage_target' => 'nullable|in:greeting,product_selection,customer_info,order_summary,confirmation',
            'is_active'    => 'boolean',
        ]);

        $relanceTemplate->update($validated);

        return response()->json($relanceTemplate->load('creator:id,name'));
    }

    public function destroy(RelanceTemplate $relanceTemplate): JsonResponse
    {
        $relanceTemplate->delete();
        return response()->json(['message' => 'Template supprimé.']);
    }
}