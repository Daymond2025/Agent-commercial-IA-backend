<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = [
        'name', 'slug', 'brand', 'description', 'price', 'sale_price', 'currency',
        'specs', 'image_url', 'images', 'is_available', 'stock',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $product->slug = static::generateUniqueSlug($product->name);
            }
        });
    }

    private static function generateUniqueSlug(string $name): string
    {
        $base  = Str::slug($name);
        $count = static::where('slug', 'like', $base . '%')->count();
        return $count > 0 ? $base . '-' . ($count + 1) : $base;
    }

    protected $casts = [
        'specs'       => 'array',
        'images'      => 'array',
        'is_available' => 'boolean',
        'price'       => 'decimal:2',
        'sale_price'  => 'decimal:2',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(WhatsappAgent::class, 'agent_product', 'product_id', 'agent_id');
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 0, ',', ' ') . ' ' . $this->currency;
    }

    public function getFormattedSalePriceAttribute(): ?string
    {
        if (!$this->sale_price) return null;
        return number_format($this->sale_price, 0, ',', ' ') . ' ' . $this->currency;
    }

    public function toAgentContext(): array
    {
        // Si prix réduit : affiche le prix promo + prix barré
        $priceLabel = $this->sale_price
            ? number_format($this->sale_price, 0, ',', ' ') . ' ' . $this->currency
              . ' (promotion, au lieu de ' . $this->formatted_price . ')'
            : $this->formatted_price;

        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'brand'       => $this->brand,
            'description' => $this->description,
            'price'       => $priceLabel,
            'specs'       => $this->specs,
            'available'   => $this->is_available,
            'stock'       => $this->stock,
        ];
    }
}