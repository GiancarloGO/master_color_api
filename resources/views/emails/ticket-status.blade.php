@component('mail::message')
# Actualización de tu ticket de soporte

Hola {{ $ticket->client->name ?? 'cliente' }},

Tu ticket **{{ $ticket->code }}** ahora está en estado:

@component('mail::panel')
{{ $statusLabels[$ticket->status] ?? $ticket->status }}
@endcomponent

**Asunto:** {{ $ticket->subject }}

@if($ticket->status === 'resuelto' && $ticket->diagnosis)
**Diagnóstico:** {{ $ticket->diagnosis }}
@endif

Gracias por confiar en Master Color.

Saludos,<br>
{{ config('app.name') }}
@endcomponent
