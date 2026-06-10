<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'reference', 'conversation_id', 'product_id',
        'customer_name', 'customer_phone', 'customer_email',
        'delivery_address', 'delivery_city', 'total_amount', 'currency',
        'status', 'assigned_coordinator_id', 'coordinator_notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->reference = 'DAY-' . date('Y') . '-' . str_pad(
                (Order::whereYear('created_at', date('Y'))->count() + 1),
                4, '0', STR_PAD_LEFT
            );
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_coordinator_id');
    }
}