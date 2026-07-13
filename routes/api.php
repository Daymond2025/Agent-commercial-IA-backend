<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RelanceTemplateController;
use App\Http\Controllers\Api\WebChatController;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook WhatsApp (public)
Route::prefix('webhook/whatsapp')->group(function () {
    Route::get('/', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/', [WhatsAppWebhookController::class, 'handle']);
});

// Auth publique
Route::post('/auth/login', [AuthController::class, 'login']);

// ── WebChat public (sans JWT) ─────────────────────────────────────────────
Route::prefix('chat')->group(function () {
    Route::get('/product/{identifier}',     [WebChatController::class, 'product']);
    Route::get('/media/{token}/{filename}', [WebChatController::class, 'serveMedia'])->middleware('throttle:300,1');
    Route::post('/start',                   [WebChatController::class, 'start'])->middleware('throttle:60,1');
    Route::post('/{token}/message',         [WebChatController::class, 'message'])->middleware('throttle:300,1');
    Route::post('/{token}/upload',          [WebChatController::class, 'upload'])->middleware('throttle:120,1');
    Route::get('/{token}/messages',         [WebChatController::class, 'messages'])->middleware('throttle:600,1');
    Route::get('/{token}/catalog',          [WebChatController::class, 'catalog'])->middleware('throttle:120,1');
});

// Routes protégées JWT
Route::middleware('auth:api')->group(function () {
    Route::post('/auth/logout',  [AuthController::class, 'logout']);
    Route::get('/auth/me',       [AuthController::class, 'me']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);

    // Stats dashboard
    Route::get('/stats/orders',        [OrderController::class, 'stats']);
    Route::get('/stats/conversations', [ConversationController::class, 'stats']);
    Route::get('/stats/agents',        [AgentController::class, 'stats']);

    // Commandes
    Route::get('/orders',                  [OrderController::class, 'index']);
    Route::get('/orders/{order}',          [OrderController::class, 'show']);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);

    // Conversations
    Route::get('/conversations',                               [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}',                [ConversationController::class, 'show']);
    Route::post('/conversations/{conversation}/send',          [ConversationController::class, 'sendMessage']);
    Route::patch('/conversations/{conversation}/toggle-ai',    [ConversationController::class, 'toggleAI']);

    // Leads à relancer
    Route::get('/leads',                         [LeadController::class, 'index']);
    Route::get('/leads/count',                   [LeadController::class, 'count']);
    Route::post('/leads/{conversation}/relance', [LeadController::class, 'relance']);

    // Templates de relance (admin + coordinateur en lecture)
    Route::get('/relance-templates',                     [RelanceTemplateController::class, 'index']);
    Route::middleware('role:admin')->group(function () {
        Route::post('/relance-templates',                [RelanceTemplateController::class, 'store']);
        Route::put('/relance-templates/{relanceTemplate}',   [RelanceTemplateController::class, 'update']);
        Route::delete('/relance-templates/{relanceTemplate}',[RelanceTemplateController::class, 'destroy']);
    });

    // Routes admin uniquement
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('products', ProductController::class);
        Route::apiResource('agents', AgentController::class);
        Route::post('/agents/{agent}/products',             [AgentController::class, 'syncProducts']);
        Route::post('/agents/{agent}/knowledge-base/upload',[AgentController::class, 'uploadKnowledge']);
        Route::delete('/agents/{agent}/knowledge-base',     [AgentController::class, 'clearKnowledge']);
        Route::get('/agents/{agent}/documents',             [AgentController::class, 'documents']);
        Route::post('/agents/{agent}/train',                [AgentController::class, 'train']);
    });
});