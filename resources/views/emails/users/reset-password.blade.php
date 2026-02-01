@component('mail::message')
# Recuperación de Contraseña

Hola {{ $name }},

Recibimos una solicitud para restablecer la contraseña de tu cuenta de empleado en Master Color.

@component('mail::button', ['url' => $url])
Restablecer Contraseña
@endcomponent

Este enlace expirará en 60 minutos.

Si no solicitaste restablecer tu contraseña, puedes ignorar este correo de forma segura.

Saludos,<br>
{{ config('app.name') }}
@endcomponent
