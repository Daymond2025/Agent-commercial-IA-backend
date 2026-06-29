<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WhatsappAgent extends Model
{
    protected $fillable = [
        'name', 'phone_number', 'support_phone', 'phone_number_id',
        'access_token', 'waba_id', 'is_active', 'persona',
        'instructions', 'knowledge_base', 'website_url', 'avatar_url',
        'relance_hours', 'auto_relance_enabled',
    ];

    protected $casts = [
        'is_active'             => 'boolean',
        'persona'               => 'array',
        'relance_hours'         => 'array',
        'auto_relance_enabled'  => 'boolean',
    ];

    protected $hidden = ['access_token'];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'agent_product', 'agent_id', 'product_id');
    }
}