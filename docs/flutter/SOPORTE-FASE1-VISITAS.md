# App de Soporte — Fase 1: Visitas a domicilio (cambios para el frontend)

Cambios de backend ya disponibles para que la app soporte **visitas a domicilio**:
datos del cliente visibles para el técnico, dirección + geolocalización del
servicio, y tipo de servicio (remoto/domicilio/taller). Más correcciones de
validación de negocio.

Contrato actualizado: [`../openapi/support-api.yaml`](../openapi/support-api.yaml)
(regenerar el cliente Dart). Envelope sin cambios:
`{ success, message, status, data, pagination, errors }`. snake_case, fechas
ISO-8601, URLs absolutas.

> Alcance: Bloques 0, 1, 2 y 7 del requerimiento. Agenda, check-in/out, reporte
> con firma, repuestos y presupuesto **NO** están en esta fase (siguiente ronda).

---

## 1. Datos del cliente en el ticket (Bloque 0)

El backend ya enviaba `client` y `rating_comment`, pero no estaban en el contrato.
Ahora están declarados en `SupportTicket` (cola y detalle de staff).

En **cola** (`GET /support/tickets`) y **detalle** (`GET /support/tickets/{id}`)
cada ticket trae:

```json
{
  "id": 4501,
  "code": "SOP-2026-0001",
  "client": { "id": 12, "name": "ACME S.A.C.", "email": "contacto@acme.com", "phone": "910000001" },
  "rating_comment": "Excelente atención"
}
```

- **App técnico:** mostrar nombre + **teléfono** del cliente en la tarjeta/detalle
  del ticket (botón llamar/WhatsApp con `client.phone`).
- `client` puede ser `null` si no se cargó; `rating_comment` es `null` hasta que
  el cliente califica.

---

## 2. Dirección + geolocalización del servicio (Bloque 1)

### 2.1 Direcciones del cliente con coordenadas

`GET /client/addresses` ahora devuelve `latitude` / `longitude` (nullable):

```json
{
  "id": 16,
  "address_full": "Av. Prueba 123",
  "district": "Lima", "province": "Lima", "department": "Lima",
  "postal_code": "15001", "reference": "Puerta azul",
  "is_main": true,
  "latitude": -12.0464,
  "longitude": -77.0428
}
```

Al **crear/editar** dirección (`POST` / `PUT /client/addresses`) la app puede
enviar `latitude` y `longitude` (números, opcionales; rango lat −90..90, lng
−180..180). Captúralas con el GPS o un picker de mapa.

### 2.2 Dirección embebida en el ticket

Cuando el ticket es a domicilio, `SupportTicket` incluye `service_address`
(objeto `Address` completo, con coordenadas) y `service_address_id`:

```json
{
  "service_type": "domicilio",
  "service_address_id": 16,
  "service_address": {
    "id": 16, "address_full": "Av. Prueba 123",
    "district": "Lima", "province": "Lima", "department": "Lima",
    "reference": "Puerta azul", "latitude": -12.0464, "longitude": -77.0428
  }
}
```

`service_address` es `null` si el servicio no es a domicilio o no se asignó dirección.

### 2.3 Botón "Cómo llegar"

Con `service_address.latitude/longitude`, abrir navegación:

```dart
// Google Maps / Waze / app de mapas por defecto
final lat = ticket.serviceAddress?.latitude;
final lng = ticket.serviceAddress?.longitude;
if (lat != null && lng != null) {
  launchUrl(Uri.parse('geo:$lat,$lng?q=$lat,$lng'));            // Android
  // o https://www.google.com/maps/search/?api=1&query=$lat,$lng (multiplataforma)
}
```

Si lat/lng son `null`, hacer fallback a buscar por texto con `address_full` +
`district`. Mostrar también un mapa embebido con el pin en esas coordenadas.

---

## 3. Tipo de servicio (Bloque 2)

Nuevo campo `service_type` en el ticket: **`remoto` | `domicilio` | `taller`**
(default `remoto`).

### Al crear ticket (`POST /client/support/tickets`)

```json
{
  "category": "falla",
  "subject": "Impresora no enciende",
  "description": "No da señal",
  "service_type": "domicilio",
  "service_address_id": 16
}
```

Reglas de validación (devuelven **422** con `errors`):
- `service_type` opcional; si se omite → `remoto`.
- Si `service_type = domicilio` → **`service_address_id` es obligatorio**.
- `service_address_id` debe ser una dirección **del propio cliente** (si no →
  422 `"La dirección no pertenece al cliente"`).

**App cliente:** al elegir "Servicio a domicilio", mostrar selector de dirección
(de `GET /client/addresses`) y enviar el `id` elegido. Para "remoto"/"taller" no
se pide dirección.

**App técnico:** usar `service_type` para enrutar la UI (badge "Domicilio" →
mostrar dirección + botón cómo llegar; "Remoto" → ocultar bloque de dirección).

---

## 4. Validaciones de negocio (Bloque 7) — manejo de errores

Las violaciones de regla de negocio en tickets ahora responden **409 Conflict**
(uniforme en `assign`, `status`, `diagnosis`), con el motivo legible en `message`:

| Acción | Caso | Código | message |
|--------|------|--------|---------|
| `PATCH /support/tickets/{id}/assign` | usuario no es técnico activo | **409** | "El usuario asignado debe ser un técnico activo" |
| `PATCH /support/tickets/{id}/assign` | ticket cerrado/cancelado | **409** | "No se puede asignar un ticket en estado 'cerrado'" |
| `POST /support/tickets/{id}/diagnosis` | ticket cerrado/cancelado | **409** | "No se puede diagnosticar un ticket en estado '...'" |
| `PATCH /support/tickets/{id}/status` | transición inválida | **409** | "No se puede cambiar el estado de '...' a '...'" |

- **409 = conflicto de estado**, no error de campos. La app debe mostrar
  `message` como toast/snackbar, **no** como error de formulario.
- **422** se mantiene solo para validación de entrada (campos faltantes/ inválidos),
  con detalle en `errors`.
- Sigue siendo buena práctica que la app **solo ofrezca acciones válidas** según
  el estado del ticket (deshabilitar "Asignar"/"Diagnosticar" en cerrados), y use
  el 409 como red de seguridad.

---

## 5. Checklist de implementación en la app

- [ ] Regenerar cliente Dart desde `support-api.yaml`.
- [ ] Mostrar `client.name` + `client.phone` en ticket (técnico): llamar/WhatsApp.
- [ ] Pantalla de direcciones: capturar/editar `latitude`/`longitude` (GPS/mapa).
- [ ] Crear ticket: selector `service_type`; si `domicilio`, exigir dirección.
- [ ] Detalle de ticket (técnico): badge de `service_type`, bloque
      `service_address` + mapa + botón "Cómo llegar".
- [ ] Manejo de **409**: toast con `message`; deshabilitar acciones inválidas.

---

## 6. Pendiente (siguiente fase, ya planificado)

Agenda/programación de visitas, check-in/out con GPS, reporte de servicio con
firma + **PDF de acta**, repuestos vinculados a inventario, y presupuesto/aprobación
para servicios fuera de garantía.
