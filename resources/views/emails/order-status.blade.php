@component('mail::message')
# Actualización de tu Pedido #{{ $order->id }}

Hola {{ $order->client->name }},

Te escribimos para informarte que el estado de tu pedido ha cambiado:

@component('mail::panel')
**Estado anterior:** {{ $statusMessages[$previousStatus] ?? $previousStatus }}
**Estado actual:** {{ $statusMessages[$order->status] ?? $order->status }}
@endcomponent

## Detalles del Pedido

**Número de pedido:** #{{ $order->id }}
**Fecha de pedido:** {{ $order->created_at->format('d/m/Y H:i') }}
**Total:** S/. {{ number_format($order->total, 2) }}

### Productos
@foreach($order->orderDetails as $detail)
- {{ $detail->product->name }} x{{ $detail->quantity }} - S/. {{ number_format($detail->subtotal, 2) }}
@endforeach

@if($order->observations)
### Observaciones
{{ $order->observations }}
@endif

@if($order->status === 'enviado')
@component('mail::panel')
🚚 **¡Tu pedido está en camino!**

Estamos preparando tu pedido para la entrega. Te contactaremos pronto para coordinar la entrega.
@endcomponent
@elseif($order->status === 'entregado')
@component('mail::panel')
✅ **¡Tu pedido ha sido entregado!**

Esperamos que disfrutes tus productos. Si tienes alguna consulta, no dudes en contactarnos.
@endcomponent
@elseif($order->status === 'cancelado')
@component('mail::panel')
❌ **Tu pedido ha sido cancelado**

Si tienes alguna pregunta sobre la cancelación, por favor contáctanos.
@endcomponent
@elseif($order->status === 'confirmado')
@component('mail::panel')
✅ **¡Tu pedido ha sido confirmado!**

Estamos procesando tu pedido y pronto comenzaremos con la preparación.
@endcomponent
@elseif($order->status === 'procesando')
@component('mail::panel')
⚡ **Tu pedido está siendo preparado**

Nuestro equipo está preparando cuidadosamente tu pedido para el envío.
@endcomponent
@endif

@component('mail::button', ['url' => config('app.frontend_url')])
Ver Detalles del Pedido
@endcomponent

Si tienes alguna pregunta sobre tu pedido, no dudes en contactarnos.

Gracias por confiar en Master Color,
{{ config('app.name') }}

---
*Este es un email automático, por favor no respondas a este mensaje.*
@endcomponent