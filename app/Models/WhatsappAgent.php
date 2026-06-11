<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WhatsappAgent extends Model
{
    protected $fillable = [
        'name', 'phone_number', 'phone_number_id',
        'access_token', 'waba_id', 'is_active', 'persona',
        'instructions', 'knowledge_base', 'website_url', 'avatar_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'persona'   => 'array',
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