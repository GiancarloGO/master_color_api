<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketStatusNotification extends Mailable
{
    use SerializesModels;

    public array $statusLabels;

    public function __construct(
        public SupportTicket $ticket,
        public ?string $previousStatus,
    ) {
        $this->statusLabels = [
            'abierto' => 'Abierto',
            'asignado' => 'Asignado',
            'en_proceso' => 'En proceso',
            'en_espera_cliente' => 'En espera de tu respuesta',
            'resuelto' => 'Resuelto',
            'cerrado' => 'Cerrado',
            'cancelado' => 'Cancelado',
        ];
    }

    public function envelope(): Envelope
    {
        $label = $this->statusLabels[$this->ticket->status] ?? $this->ticket->status;

        return new Envelope(
            subject: "Master Color - Ticket {$this->ticket->code}: {$label}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.ticket-status',
            with: [
                'ticket' => $this->ticket,
                'statusLabels' => $this->statusLabels,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
