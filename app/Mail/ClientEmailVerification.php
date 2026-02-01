<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ClientEmailVerification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $client;

    /**
     * Number of times to retry the job
     */
    public $tries = 3;

    /**
     * Timeout for the job in seconds
     */
    public $timeout = 60;

    public function __construct($client)
    {
        $this->client = $client;
    }

    public function build()
    {
        $url = config('app.frontend_url') . '/verify-email?token=' . $this->client->verification_token;

        return $this->subject('Verifica tu correo electrónico')
                    ->markdown('emails.clients.verify')
                    ->with([
                        'name' => $this->client->name,
                        'url' => $url,
                    ]);
    }
}
