<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketVisit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ticket_id',
        'technician_id',
        'checkin_at',
        'checkin_latitude',
        'checkin_longitude',
        'checkout_at',
        'checkout_latitude',
        'checkout_longitude',
        'work_done',
        'client_signed_name',
        'signature_path',
        'report_pdf_path',
        'reported_at',
    ];

    protected $casts = [
        'checkin_at' => 'datetime',
        'checkout_at' => 'datetime',
        'reported_at' => 'datetime',
        'checkin_latitude' => 'decimal:7',
        'checkin_longitude' => 'decimal:7',
        'checkout_latitude' => 'decimal:7',
        'checkout_longitude' => 'decimal:7',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * Tiempo en sitio en minutos (null si la visita sigue abierta).
     */
    public function durationMinutes(): ?int
    {
        if (!$this->checkin_at || !$this->checkout_at) {
            return null;
        }

        return $this->checkin_at->diffInMinutes($this->checkout_at);
    }
}
