<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'whatsapp_agent_id', 'customer_phone', 'customer_name',
        'status', 'stage', 'last_message_at', 'window_expires_at', 'collected_data',
        'ai_active', 'ai_paused_at',
    ];

    protected $casts = [
        'collected_data'   => 'array',
        'last_message_at'  => 'datetime',
        'window_expires_at'=> 'datetime',
        'ai_paused_at'     => 'datetime',
        'ai_active'        => 'boolean',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(WhatsappAgent::class, 'whatsapp_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function isWithin24hWindow(): bool
    {
        return $this->window_expires_at && now()->lt($this->window_expires_at);
    }
}