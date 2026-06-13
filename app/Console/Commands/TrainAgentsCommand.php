<?php

namespace App\Console\Commands;

use App\Models\WhatsappAgent;
use App\Services\AI\ClaudeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TrainAgentsCommand extends Command
{
    protected $signature   = 'agents:train {--agent= : ID d\'un agent spécifique}';
    protected $description = 'Auto-formation des agents IA à partir des conversations réussies';

    public function handle(ClaudeService $claude): int
    {
        $query = WhatsappAgent::where('is_active', true);

        if ($agentId = $this->option('agent')) {
            $query->where('id', $agentId);
        }

        $agents = $query->get();

        foreach ($agents as $agent) {
            $this->info("Formation de l'agent : {$agent->name}");

            $insights = $claude->trainAgent($agent);

            if (!$insights) {
                $this->warn("  → Pas assez de données (min. 3 ventes confirmées requises).");
                continue;
            }

            $separator = "\n\n---\n\n";
            $header    = "AUTO-FORMATION (" . now()->format('d/m/Y H:i') . ") :";
            $agent->update([
                'knowledge_base' => trim(($agent->knowledge_base ?? '') . $separator . $header . "\n" . $insights),
            ]);

            $this->info("  ✓ Knowledge base enrichie (" . strlen($insights) . " caractères ajoutés).");
            Log::info('Agent trained', ['agent' => $agent->name, 'chars' => strlen($insights)]);
        }

        return Command::SUCCESS;
    }
}