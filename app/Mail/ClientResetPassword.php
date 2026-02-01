<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ClientResetPassword extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $token;
    public $email;
    public $name;

    /**
     * Number of times to retry the job
     */
    public $tries = 3;

    /**
     * Timeout for the job in seconds
     */
    public $timeout = 60;

    public function __construct($token, $email, $name)
    {
        $this->token = $token;
        $this->email = $email;
        $this->name = $name;
    }

    public function build()
    {
        $url = config('app.frontend_url') . '/reset-password?token=' . $this->token . '&email=' . $this->email;

        return $this->subject('Recuperación de contraseña')
                    ->markdown('emails.clients.reset-password')
                    ->with([
                        'name' => $this->name,
                        'url' => $url,
                    ]);
    }
}
