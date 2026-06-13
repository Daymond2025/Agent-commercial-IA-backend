<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDocument extends Model
{
    protected $fillable = [
        'whatsapp_agent_id', 'original_name', 'file_path', 'mime_type', 'extracted_text',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(WhatsappAgent::class, 'whatsapp_agent_id');
    }
}