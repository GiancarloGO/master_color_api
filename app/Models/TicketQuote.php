<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketQuote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ticket_id',
        'labor_cost',
        'parts_cost',
        'total',
        'currency',
        'status',
        'note',
        'created_by_user_id',
        'decided_at',
    ];

    protected $casts = [
        'labor_cost' => 'decimal:2',
        'parts_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
