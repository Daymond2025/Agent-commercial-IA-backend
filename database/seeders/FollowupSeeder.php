<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Followup;
use Illuminate\Database\Seeder;

class FollowupSeeder extends Seeder
{
    public function run(): void
    {
        $map = [
            'Diomandé Lassina' => ['manual_relance', 'pending',  ['client_name' => 'Lassina', 'product' => 'Acer Aspire 5'], now()->addHours(2),  null],
            'Bintou Kouyaté'   => ['manual_relance', 'sent',     ['client_name' => 'Bintou',  'product' => 'Asus VivoBook 15'], now()->subHours(1), now()->subHours(1)],
            'Amara Diallo'     => ['manual_relance', 'failed',   ['client_name' => 'Amara'],  now()->subHours(3), null],
        ];

        foreach ($map as $customerName => [$template, $status, $params, $scheduledAt, $sentAt]) {
            $conv = Conversation::where('customer_name', $customerName)->first();
            if (!$conv) continue;

            Followup::firstOrCreate(
                ['conversation_id' => $conv->id, 'template_name' => $template, 'status' => $status],
                [
                    'scheduled_at'    => $scheduledAt,
                    'template_params' => $params,
                    'sent_at'         => $sentAt,
                ]
            );
        }
    }
}