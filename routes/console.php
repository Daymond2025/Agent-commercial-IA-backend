<?php

use App\Jobs\SendFollowups;
use Illuminate\Support\Facades\Schedule;

// Vérifie et envoie les relances toutes les 5 minutes
Schedule::job(new SendFollowups)->everyFiveMinutes();

// Relances automatiques IA — toutes les 2h entre 8h et 20h
Schedule::command('relances:auto')
    ->everyTwoHours()
    ->between('08:00', '20:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/auto-relance.log'));

// Marque les conversations abandonnées (inactives depuis 72h)
Schedule::call(function () {
    \App\Models\Conversation::where('status', 'active')
        ->where('last_message_at', '<', now()->subHours(72))
        ->update(['status' => 'abandoned']);
})->hourly();