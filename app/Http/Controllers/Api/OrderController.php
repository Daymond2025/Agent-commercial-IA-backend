<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['product', 'coordinator', 'conversation.agent'])
            ->orderByDesc('created_at');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('reference', 'like', "%{$request->search}%")
                    ->orWhere('customer_name', 'like', "%{$request->search}%")
                    ->orWhere('customer_phone', 'like', "%{$request->search}%");
            });
        }

        $user = auth()->user();
        if ($user->role === 'coordinator') {
            $query->where('assigned_coordinator_id', $user->id);
        }

        return response()->json($query->paginate(20));
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(
            $order->load(['product', 'coordinator', 'conversation.messages', 'conversation.agent'])
        );
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:confirmed,processing,shipped,delivered,cancelled',
            'coordinator_notes' => 'nullable|string',
        ]);

        $order->update([
            'status' => $request->status,
            'coordinator_notes' => $request->coordinator_notes ?? $order->coordinator_notes,
        ]);

        return response()->json($order);
    }

    public function stats(): JsonResponse
    {
        $today = now()->startOfDay();

        return response()->json([
            'total' => Order::count(),
            'today' => Order::where('created_at', '>=', $today)->count(),
            'pending' => Order::where('status', 'pending')->count(),
            'confirmed' => Order::where('status', 'confirmed')->count(),
            'delivered' => Order::where('status', 'delivered')->count(),
            'revenue_total' => Order::whereIn('status', ['confirmed', 'processing', 'shipped', 'delivered'])
                ->sum('total_amount'),
            'revenue_today' => Order::whereIn('status', ['confirmed', 'processing', 'shipped', 'delivered'])
                ->where('created_at', '>=', $today)
                ->sum('total_amount'),
        ]);
    }
}