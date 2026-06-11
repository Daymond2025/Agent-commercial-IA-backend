<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook WhatsApp (public)
Route::prefix('webhook/whatsapp')->group(function () {
    Route::get('/', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/', [WhatsAppWebhookController::class, 'handle']);
});

// Auth publique
Route::post('/auth/login', [AuthController::class, 'login']);

// Routes protégées JWT
Route::middleware('auth:api')->group(function () {
    Route::post('/auth/logout',    [AuthController::class, 'logout']);
    Route::get('/auth/me',         [AuthController::class, 'me']);
    Route::put('/auth/password',   [AuthController::class, 'changePassword']);

    // Stats dashboard
    Route::get('/stats/orders',        [OrderController::class, 'stats']);
    Route::get('/stats/conversations', [ConversationController::class, 'stats']);
    Route::get('/stats/agents',        [AgentController::class, 'stats']);

    // Commandes
    Route::get('/orders',                    [OrderController::class, 'index']);
    Route::get('/orders/{order}',            [OrderController::class, 'show']);
    Route::patch('/orders/{order}/status',   [OrderController::class, 'updateStatus']);

    // Conversations
    Route::get('/conversations',                    [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}',     [ConversationController::class, 'show']);

    // Leads à relancer
    Route::get('/leads',                            [LeadController::class, 'index']);
    Route::get('/leads/count',                      [LeadController::class, 'count']);
    Route::post('/leads/{conversation}/relance',    [LeadController::class, 'relance']);

    // Routes admin uniquement
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('products', ProductController::class);
        Route::apiResource('agents', AgentController::class);
        Route::post('/agents/{agent}/products', [AgentController::class, 'syncProducts']);
    });
});