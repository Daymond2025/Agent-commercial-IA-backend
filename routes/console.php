<?php

use App\Jobs\SendFollowups;
use Illuminate\Support\Facades\Schedule;

// Vérifie et envoie les relances toutes les 5 minutes
Schedule::job(new SendFollowups)->everyFiveMinutes();

// Marque les conversations abandonnées (inactives depuis 72h)
Schedule::call(function () {
    \App\Models\Conversation::where('status', 'active')
        ->where('last_message_at', '<', now()->subHours(72))
        ->update(['status' => 'abandoned']);
})->hourly();