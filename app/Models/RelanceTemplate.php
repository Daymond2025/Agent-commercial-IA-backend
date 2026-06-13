<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelanceTemplate extends Model
{
    protected $fillable = ['name', 'message', 'stage_target', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}