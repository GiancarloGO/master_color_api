<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderStatusNotification extends Mailable
{
    use SerializesModels;

    public $order;
    public $previousStatus;
    public $statusMessages;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, string $previousStatus)
    {
        $this->order = $order;
        $this->previousStatus = $previousStatus;
        $this->statusMessages = [
            'pendiente_pago' => 'Esperando pago',
            'pendiente' => 'Pendiente de confirmación',
            'confirmado' => 'Confirmado',
            'procesando' => 'En preparación',
            'enviado' => 'Enviado',
            'entregado' => 'Entregado',
            'cancelado' => 'Cancelado',
            'pago_fallido' => 'Pago fallido'
        ];
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $statusMessage = $this->statusMessages[$this->order->status] ?? $this->order->status;
        
        return new Envelope(
            subject: "Master Color - Tu pedido #{$this->order->id} está {$statusMessage}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.order-status',
            with: [
                'order' => $this->order,
                'previousStatus' => $this->previousStatus,
                'statusMessages' => $this->statusMessages,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
