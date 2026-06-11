<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Address extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'address_full',
        'district',
        'province',
        'department',
        'postal_code',
        'reference',
        'is_main',
        'latitude',
        'longitude'
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    /**
     * Get the client that owns the address.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the orders for the address.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'delivery_address_id');
    }
}
