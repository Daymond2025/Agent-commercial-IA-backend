<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\WhatsappAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        // PHP convertit hub.mode → hub_mode automatiquement
        $mode      = $request->query('hub_mode')         ?? $request->query('hub.mode');
        $token     = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge')    ?? $request->query('hub.challenge');

        if ($mode === 'subscribe' && $token === config('whatsapp.verify_token')) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request): Response
    {
        $payload = $request->all();

        Log::debug('WhatsApp webhook received', ['payload' => $payload]);

        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return response('OK', 200);
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if ($change['field'] !== 'messages') continue;

                $value = $change['value'];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                $agent = WhatsappAgent::where('phone_number_id', $phoneNumberId)
                    ->where('is_active', true)
                    ->first();

                if (!$agent) {
                    Log::warning('No active agent for phone_number_id', ['id' => $phoneNumberId]);
                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    ProcessWhatsAppMessage::dispatch($agent->id, $message, $value['contacts'][0] ?? []);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->handleStatusUpdate($status);
                }
            }
        }

        return response('OK', 200);
    }

    private function handleStatusUpdate(array $status): void
    {
        $messageId = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;

        if ($messageId && $newStatus) {
            \App\Models\Message::where('whatsapp_message_id', $messageId)
                ->update(['status' => $newStatus]);
        }
    }
}