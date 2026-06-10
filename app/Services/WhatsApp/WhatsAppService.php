<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappAgent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $apiVersion;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiVersion = config('whatsapp.api_version', 'v20.0');
        $this->baseUrl = "https://graph.facebook.com/{$this->apiVersion}";
    }

    public function sendText(WhatsappAgent $agent, string $to, string $message): array
    {
        return $this->sendRequest($agent, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $message],
        ]);
    }

    public function sendTemplate(
        WhatsappAgent $agent,
        string $to,
        string $templateName,
        array $params = [],
        string $language = 'fr'
    ): array {
        $components = [];

        if (!empty($params)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn($p) => ['type' => 'text', 'text' => $p],
                    $params
                ),
            ];
        }

        return $this->sendRequest($agent, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ]);
    }

    public function sendInteractiveButtons(
        WhatsappAgent $agent,
        string $to,
        string $bodyText,
        array $buttons
    ): array {
        $formattedButtons = array_map(fn($btn, $i) => [
            'type' => 'reply',
            'reply' => ['id' => "btn_{$i}", 'title' => $btn],
        ], $buttons, array_keys($buttons));

        return $this->sendRequest($agent, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $bodyText],
                'action' => ['buttons' => $formattedButtons],
            ],
        ]);
    }

    public function markAsRead(WhatsappAgent $agent, string $messageId): void
    {
        $this->sendRequest($agent, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    private function sendRequest(WhatsappAgent $agent, array $payload): array
    {
        try {
            $response = Http::withToken($agent->access_token)
                ->post("{$this->baseUrl}/{$agent->phone_number_id}/messages", $payload);

            if ($response->failed()) {
                Log::error('WhatsApp API error', [
                    'agent' => $agent->phone_number,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('WhatsApp send failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
}