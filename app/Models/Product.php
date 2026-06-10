<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name', 'brand', 'description', 'price', 'currency',
        'specs', 'image_url', 'is_available', 'stock',
    ];

    protected $casts = [
        'specs' => 'array',
        'is_available' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 0, ',', ' ') . ' ' . $this->currency;
    }

    public function toAgentContext(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand,
            'description' => $this->description,
            'price' => $this->formatted_price,
            'specs' => $this->specs,
            'available' => $this->is_available,
            'stock' => $this->stock,
        ];
    }
}