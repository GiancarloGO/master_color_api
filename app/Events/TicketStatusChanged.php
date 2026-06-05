<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $ticketId,
        public ?string $fromStatus,
        public string $toStatus,
        public string $actorType, // client | user | system
    ) {}
}
