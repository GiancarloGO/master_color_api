<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'service_type',
        'service_address_id',
        'scheduled_at',
        'scheduled_window_minutes',
        'is_warranty_covered',
        'sla_due_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'rating',
        'rating_comment',
        'diagnosis',
        'parts_used',
        'reminder_sent_at',
    ];

    protected $casts = [
        'is_warranty_covered' => 'boolean',
        'sla_due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'rating' => 'integer',
        'scheduled_at' => 'datetime',
        'scheduled_window_minutes' => 'integer',
        'reminder_sent_at' => 'datetime',
    ];

    /**
     * Horas antes del vencimiento del SLA en que un ticket se marca "por vencer".
     */
    public const SLA_DUE_SOON_HOURS = 4;

    /**
     * Estados en los que el SLA ya no aplica (atendido o terminal).
     */
    private const SLA_CLOSED_STATUSES = ['resuelto', 'cerrado', 'cancelado'];

    /**
     * Estado del SLA del ticket: on_track | due_soon | breached, o null si no aplica.
     */
    public function slaStatus(): ?string
    {
        if (!$this->sla_due_at || in_array($this->status, self::SLA_CLOSED_STATUSES, true)) {
            return null;
        }

        if ($this->sla_due_at->isPast()) {
            return 'breached';
        }

        if ($this->sla_due_at->lte(now()->addHours(self::SLA_DUE_SOON_HOURS))) {
            return 'due_soon';
        }

        return 'on_track';
    }

    /**
     * Tickets abiertos con SLA aplicable (base para vencidos / por vencer).
     */
    public function scopeOpenForSla(Builder $query): Builder
    {
        return $query->whereNotNull('sla_due_at')
            ->whereNotIn('status', self::SLA_CLOSED_STATUSES);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function soldUnit(): BelongsTo
    {
        return $this->belongsTo(SoldUnit::class);
    }

    /**
     * Dirección a la que viaja el técnico (solo servicios a domicilio).
     */
    public function serviceAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'service_address_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Repuestos consumidos en el ticket (descuentan inventario).
     */
    public function parts(): HasMany
    {
        return $this->hasMany(TicketPart::class, 'ticket_id');
    }

    /**
     * Visitas en sitio del técnico (check-in/out + reporte de servicio).
     */
    public function visits(): HasMany
    {
        return $this->hasMany(TicketVisit::class, 'ticket_id');
    }

    /**
     * Cotizaciones del ticket (una fila por versión).
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(TicketQuote::class, 'ticket_id');
    }

    /**
     * Cotización vigente (la más reciente).
     */
    public function latestQuote(): HasOne
    {
        return $this->hasOne(TicketQuote::class, 'ticket_id')->latestOfMany();
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
