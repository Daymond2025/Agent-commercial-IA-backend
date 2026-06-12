<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Followup;
use App\Models\Message;
use App\Services\AI\ClaudeService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoRelanceCommand extends Command
{
    protected $signature   = 'relances:auto {--dry-run : Affiche les leads sans envoyer}';
    protected $description = 'Envoie automatiquement jusqu\'à 3 relances IA par lead actif par jour (fenêtre 24h)';

    private const MAX_PER_DAY       = 3;  // relances max par lead par jour
    private const MIN_HOURS_BETWEEN = 2;  // heures min entre deux relances
    private const MAX_LEADS_PER_RUN = 15; // sécurité : limite par exécution

    public function handle(ClaudeService $claude, WhatsAppService $whatsapp): int
    {
        $dryRun = $this->option('dry-run');

        $leads = $this->getLeadsToRelance();

        if ($leads->isEmpty()) {
            $this->info('Aucun lead éligible à relancer.');
            return Command::SUCCESS;
        }

        $this->info("Leads éligibles : {$leads->count()}");

        $sent   = 0;
        $skipped = 0;

        foreach ($leads as $conv) {
            // Vérifications d'éligibilité
            if (!$this->isEligible($conv)) {
                $skipped++;
                continue;
            }

            $firstName = explode(' ', trim($conv->customer_name ?? 'client'))[0];

            if ($dryRun) {
                $this->line("  [DRY-RUN] Relancerait : {$conv->customer_name} ({$conv->customer_phone}) — étape: {$conv->stage}");
                continue;
            }

            try {
                // Générer le message via Claude
                $message = $claude->generateRelanceMessage($conv);

                // Envoyer via WhatsApp
                $whatsapp->sendText($conv->agent, $conv->customer_phone, $message);

                // Persister le message en base
                Message::create([
                    'conversation_id' => $conv->id,
                    'direction'       => 'outbound',
                    'type'            => 'text',
                    'content'         => $message,
                    'status'          => 'sent',
                ]);

                // Enregistrer le followup
                Followup::create([
                    'conversation_id' => $conv->id,
                    'scheduled_at'    => now(),
                    'template_name'   => 'auto_relance',
                    'template_params' => ['message' => $message],
                    'status'          => 'sent',
                    'sent_at'         => now(),
                ]);

                // Mettre à jour last_message_at
                $conv->update(['last_message_at' => now()]);

                $sent++;
                $this->line("  ✓ Relancé : {$conv->customer_name} — \"{$message}\"");

            } catch (\Exception $e) {
                Log::error('AutoRelance failed', [
                    'conversation_id' => $conv->id,
                    'error'           => $e->getMessage(),
                ]);
                $this->warn("  ✗ Échec pour {$conv->customer_name} : {$e->getMessage()}");

                Followup::create([
                    'conversation_id' => $conv->id,
                    'scheduled_at'    => now(),
                    'template_name'   => 'auto_relance',
                    'template_params' => [],
                    'status'          => 'failed',
                ]);
            }
        }

        $this->info("Résultat : {$sent} envoyés, {$skipped} ignorés.");
        Log::info('AutoRelance terminé', ['sent' => $sent, 'skipped' => $skipped]);

        return Command::SUCCESS;
    }

    private function getLeadsToRelance()
    {
        return Conversation::with(['agent', 'followups'])
            ->where(function ($q) {
                $q->where('status', 'abandoned')
                  ->orWhere(function ($inner) {
                      $inner->where('status', 'active')
                            ->where('last_message_at', '<', now()->subHour());
                  });
            })
            ->whereNotIn('status', ['confirmed', 'completed'])
            // Fenêtre 24h Meta encore ouverte
            ->where('window_expires_at', '>', now())
            ->orderByRaw("CASE
                WHEN stage = 'confirmation'      THEN 1
                WHEN stage = 'order_summary'     THEN 2
                WHEN stage = 'customer_info'     THEN 3
                WHEN stage = 'product_selection' THEN 4
                WHEN stage = 'greeting'          THEN 5
                ELSE 6 END")
            ->limit(self::MAX_LEADS_PER_RUN)
            ->get();
    }

    private function isEligible(Conversation $conv): bool
    {
        // Vérifier que l'agent existe et est actif
        if (!$conv->agent || !$conv->agent->is_active) {
            return false;
        }

        // Compter les relances (auto + manuelles) envoyées aujourd'hui
        $relancesToday = $conv->followups
            ->whereIn('template_name', ['auto_relance', 'manual_relance'])
            ->where('status', 'sent')
            ->filter(fn($f) => $f->sent_at && $f->sent_at->isToday())
            ->count();

        if ($relancesToday >= self::MAX_PER_DAY) {
            return false;
        }

        // Vérifier l'espacement minimum entre deux relances
        $lastRelance = $conv->followups
            ->whereIn('template_name', ['auto_relance', 'manual_relance'])
            ->where('status', 'sent')
            ->sortByDesc('sent_at')
            ->first();

        if ($lastRelance && $lastRelance->sent_at) {
            $hoursSinceLast = $lastRelance->sent_at->diffInHours(now());
            if ($hoursSinceLast < self::MIN_HOURS_BETWEEN) {
                return false;
            }
        }

        return true;
    }
}