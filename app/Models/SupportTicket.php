<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'client_id',
        'sold_unit_id',
        'product_id',
        'category',
        'priority',
        'subject',
        'description',
        'status',
        'assigned_user_id',
        'channel',
        'is_warranty_covered',
        'sla_due_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'rating',
        'rating_comment',
        'diagnosis',
        'parts_used',
    ];

    protected $casts = [
        'is_warranty_covered' => 'boolean',
        'sla_due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'rating' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function soldUnit(): BelongsTo
    {
        return $this->belongsTo(SoldUnit::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id');
    }

    /**
     * Mensajes visibles para el cliente (excluye notas internas).
     */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('is_internal', false);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class, 'ticket_id');
    }
}
