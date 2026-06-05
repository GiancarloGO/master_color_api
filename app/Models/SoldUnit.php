<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class SoldUnit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'product_id',
        'order_id',
        'order_detail_id',
        'serial_number',
        'purchase_date',
        'warranty_months',
        'warranty_expires_at',
        'registration_source',
        'proof_file_path',
        'status',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'warranty_expires_at' => 'date',
        'warranty_months' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderDetail(): BelongsTo
    {
        return $this->belongsTo(OrderDetail::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /**
     * ¿La garantía sigue vigente hoy?
     */
    public function getWarrantyActiveAttribute(): bool
    {
        if (!$this->warranty_expires_at) {
            return false;
        }

        return $this->warranty_expires_at->endOfDay()->isFuture();
    }

    /**
     * Estado legible de la garantía: vigente | vencida | sin_garantia.
     */
    public function getWarrantyStatusAttribute(): string
    {
        if ($this->warranty_months === 0 || !$this->warranty_expires_at) {
            return 'sin_garantia';
        }

        return $this->warranty_active ? 'vigente' : 'vencida';
    }

    /**
     * Días restantes de garantía (0 si ya venció o no aplica).
     */
    public function getWarrantyDaysRemainingAttribute(): int
    {
        if (!$this->warranty_active) {
            return 0;
        }

        return (int) ceil(Carbon::now()->diffInDays($this->warranty_expires_at->endOfDay(), false));
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeWarrantyActive(Builder $query): Builder
    {
        return $query->whereNotNull('warranty_expires_at')
            ->whereDate('warranty_expires_at', '>=', Carbon::today());
    }
}
