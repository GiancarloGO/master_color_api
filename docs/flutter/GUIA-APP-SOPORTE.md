# Guía de integración — App de Soporte Técnico (Backend Fase 1)

Estado del backend frente al requerimiento `SOPORTE-FASE1-VISITAS.md`. **Los 10 bloques
están implementados.** Esta guía mapea cada requerimiento con el endpoint real, su
contrato y notas de uso para la app móvil.

> Contrato completo y fuente de verdad: [`docs/openapi/support-api.yaml`](../openapi/support-api.yaml)
> (regenerar el cliente Dart desde ahí).

---

## Convenciones generales

- **Envelope** de toda respuesta:
  ```json
  { "success": true, "message": "...", "status": 200, "data": {}, "pagination": null, "errors": null }
  ```
- **snake_case**, fechas **ISO-8601**, URLs absolutas.
- **Autenticación:**
  - Cliente → header `Authorization: Bearer <client_jwt>` (rutas `/client/...`).
  - Staff/Técnico → header `Authorization: Bearer <staff_jwt>` (rutas `/support/...`).
- **Paginación:** los listados aceptan `?page` y `?per_page` (default 15) y devuelven `pagination`.
- **Códigos de error relevantes:** `401` no autenticado · `404` no encontrado ·
  `409` conflicto de reglas de negocio (transición/estado inválido) · `422` validación.

---

## Estado por bloque

| # | Bloque | Estado | Endpoints clave |
|---|--------|--------|-----------------|
| 0 | `client` + `rating_comment` en detalle | ✅ | `GET /support/tickets/{id}` |
| 1 | Dirección + lat/lng + "cómo llegar" | ✅ | `GET /client/addresses`, `service_address` en ticket |
| 2 | `service_type` (remoto/domicilio/taller) | ✅ | `POST /client/support/tickets` |
| 3 | Agenda / programación de visitas | ✅ | `PATCH .../schedule`, `GET .../agenda` |
| 4 | Check-in/out + reporte con firma | ✅ | `POST .../check-in`, `.../check-out`, `.../service-report` |
| 5 | Repuestos vinculados a stock | ✅ | `GET /support/parts`, `POST .../parts` |
| 6 | Presupuesto / aprobación | ✅ | `POST .../quote`, `.../quote/approve|reject` |
| 7 | Validaciones de negocio | ✅ | (transversal, ver §7) |
| 8 | Perfil del técnico (zona/especialidad) | ✅ | `GET /support/technicians`, `PATCH .../me` |
| 9 | SLA / push / historial | ✅ | `GET .../sla`, push FCM, `GET .../units/{id}/history` |

---

## 0 · Detalle del ticket (cliente y datos de contacto)

El detalle ya incluye el bloque `client`, `rating_comment` y todos los datos de servicio.

`GET /support/tickets/{id}` (staff) · `GET /client/support/tickets/{id}` (cliente)

```jsonc
{
  "id": 4501, "code": "SOP-2026-0001", "status": "en_proceso",
  "service_type": "domicilio",
  "sla_status": "due_soon",                 // ← §9
  "rating": null, "rating_comment": null,
  "client": { "id": 12, "name": "ACME S.A.C.", "email": "...", "phone": "910000001" },
  "service_address": { /* ver §1 */ },
  "parts": [ /* ver §5 */ ],
  "visits": [ /* ver §4 */ ],
  "quote": { /* ver §6 */ },
  "messages": [...], "attachments": [...], "status_history": [...]
}
```

> En la **cola** (`GET /support/tickets`, `/mine`, `/agenda`, `/sla`) cada ítem ya trae
> `client` y `service_address` para no tener que pedir el detalle.

---

## 1 · Dirección de servicio + geolocalización

### Direcciones del cliente (para elegir a dónde va el técnico)
`GET /client/addresses` → cada dirección incluye `latitude`/`longitude` (pueden ser `null`).

Al **crear/editar** dirección (`POST`/`PUT /client/addresses`) se pueden enviar:
```json
{ "latitude": -12.0464, "longitude": -77.0428 }
```
Validación: `latitude` ∈ [-90,90], `longitude` ∈ [-180,180].

### `service_address` en el ticket
Si el ticket es a domicilio, el detalle y la cola exponen:
```json
"service_address": {
  "id": 16, "address_full": "Av. Prueba 123",
  "district": "Lima", "province": "Lima", "department": "Lima",
  "postal_code": "15001", "reference": "Puerta azul",
  "latitude": -12.0464, "longitude": -77.0428
}
```

### Botón "Cómo llegar"
Con `latitude`/`longitude` abrir:
- Google Maps: `https://www.google.com/maps/dir/?api=1&destination=LAT,LNG`
- Waze: `https://waze.com/ul?ll=LAT,LNG&navigate=yes`
- Genérico: `geo:LAT,LNG`

Si vienen en `null`, caer al texto `address_full` como destino.

---

## 2 · Tipo de servicio

El cliente lo define al crear el ticket:

`POST /client/support/tickets`
```json
{
  "sold_unit_id": 1024,            // opcional
  "category": "falla",             // garantia|instalacion|falla|consulta|otro
  "priority": "alta",              // baja|media|alta|urgente (opcional, default media)
  "subject": "La máquina no enciende",
  "description": "...",
  "service_type": "domicilio",     // remoto|domicilio|taller (default remoto)
  "service_address_id": 16         // OBLIGATORIO si service_type = domicilio
}
```
- Si `service_type = domicilio` y falta `service_address_id` → `422`.
- La dirección debe pertenecer al cliente → si no, `422`.

---

## 3 · Agenda / programación de visitas (staff)

### Programar / reprogramar
`PATCH /support/tickets/{id}/schedule`
```json
{ "scheduled_at": "2026-06-10T15:00:00Z", "scheduled_window_minutes": 60, "note": "..." }
```
El ticket expone `scheduled_at` y `scheduled_window_minutes`.

### Mi agenda del día
`GET /support/tickets/agenda?date=YYYY-MM-DD` (default hoy)
→ tickets del técnico autenticado con visita ese día, ordenados por hora, con
`client` y `service_address` incluidos para armar la ruta.

---

## 4 · Ejecución en sitio: check-in/out + acta firmada (staff)

Toda visita se representa como un objeto `TicketVisit` (ver `visits[]` en el detalle).

### Check-in (llegada)
`POST /support/tickets/{id}/check-in`
```json
{ "latitude": -12.046, "longitude": -77.042, "at": "2026-06-10T15:05:00Z" }
```
Todos los campos son opcionales (`at` default = ahora). → `409` si ya hay una visita
en curso sin check-out.

### Check-out (salida)
`POST /support/tickets/{id}/check-out` — mismo body. → `409` si no hay visita abierta.
El backend calcula `duration_minutes` (tiempo en sitio).

### Reporte de servicio / acta de conformidad
`POST /support/tickets/{id}/service-report`

Acepta **JSON** (firma base64) o **multipart/form-data** (firma y fotos como archivo):

```jsonc
{
  "work_done": "Se reemplazó el fusor y se calibró.",   // requerido
  "client_signed_name": "Juan Pérez",
  "client_signature": "data:image/png;base64,iVBORw0...", // base64 (JSON)
  // — o, en multipart: client_signature_file = <archivo imagen>
  "parts": [ { "stock_id": 5, "qty": 1 } ],   // descuentan inventario (§5)
  "photos": [ /* archivos, solo multipart, máx 10 */ ],
  "resolve": true                              // marca el ticket como 'resuelto'
}
```

Respuesta: el `TicketVisit` con el **acta en PDF** generada:
```json
{
  "id": 8, "ticket_id": 4501, "technician_name": "Carlos Pérez",
  "checkin_at": "...", "checkout_at": "...", "duration_minutes": 45,
  "work_done": "...", "client_signed_name": "Juan Pérez",
  "signature_url": "https://.../sign_....png",
  "report_pdf_url": "https://.../acta_8_....pdf",   // ← acta de conformidad
  "reported_at": "..."
}
```

> La app ya captura firma e imágenes: enviar la firma como base64 (JSON) es lo más simple;
> si se mandan fotos, usar multipart con `client_signature_file` y `photos[]`.

---

## 5 · Repuestos vinculados al inventario (staff)

### Buscar repuesto en inventario
`GET /support/parts?search=fusor`
```json
{ "stock_id": 5, "product_name": "Fusor Konica C458", "sku": "FUS-C458",
  "available_qty": 12, "purchase_price": 180.00 }
```

### Registrar consumo (descuenta stock)
`POST /support/tickets/{id}/parts`
```json
{ "stock_id": 5, "quantity": 1, "unit_cost": 180.00 }  // unit_cost opcional
```
→ `409` si no hay stock suficiente o el ticket es terminal.

### Quitar repuesto (revierte el descuento)
`DELETE /support/tickets/{id}/parts/{partId}`

Los repuestos del ticket se ven en `parts[]` del detalle:
```json
{ "id": 12, "stock_id": 5, "product_name": "Fusor Konica C458",
  "sku": "FUS-C458", "quantity": 1, "unit_cost": 180.00 }
```

---

## 6 · Presupuesto / aprobación (fuera de garantía)

### Crear cotización (staff)
`POST /support/tickets/{id}/quote`
```json
{ "labor_cost": 120.00, "parts_cost": 50.00, "currency": "PEN", "note": "..." }
```
- Si se omite `parts_cost`, se calcula de los repuestos ya registrados.
- Mueve el ticket a estado **`en_espera_aprobacion`**.

### Decisión del cliente
`POST /client/support/tickets/{id}/quote/approve`
`POST /client/support/tickets/{id}/quote/reject`
- Tras decidir, el ticket vuelve a `en_proceso`. → `409` si la cotización ya fue resuelta.

La cotización vigente se ve en `quote` del detalle:
```json
{ "id": 30, "labor_cost": 120.00, "parts_cost": 50.00, "total": 170.00,
  "currency": "PEN", "status": "pendiente", "decided_at": null }
```

---

## 7 · Validaciones de negocio (qué esperar de la app)

- **Asignar** solo admite usuarios con rol **Técnico activo** → si no, `409`.
- **Asignar / diagnosticar / programar / cotizar / registrar repuestos** sobre un ticket
  **terminal** (`cerrado`/`cancelado`) → `409`.
- Transiciones de estado controladas por máquina de estados; un cambio inválido → `409`.
  La app debe ofrecer solo las transiciones válidas (mantener consistencia con el backend).

---

## 8 · Perfil del técnico (asignación inteligente)

### Listar técnicos (con filtros)
`GET /support/technicians?specialty=falla&zone=Miraflores&available_only=true&search=`
```json
{ "id": 7, "name": "Carlos Pérez", "email": "...", "phone": "900000001",
  "active": true, "is_available": true,
  "specialties": ["instalacion","falla"],
  "coverage_zones": ["Miraflores","San Isidro"] }
```
- `specialty` ∈ {garantia, instalacion, falla, consulta, otro}.
- `zone` = distrito/zona. `available_only=true` filtra disponibles.

→ úsalo para **sugerir el técnico adecuado** por zona/especialidad al asignar.

### El técnico edita su propio perfil
`PATCH /support/technicians/me`
```json
{ "specialties": ["garantia","falla"], "coverage_zones": ["Surco","La Molina"], "is_available": false }
```

---

## 9 · SLA, notificaciones push e historial

### SLA — flag y lista de escalamiento
- Cada ticket expone **`sla_status`**: `on_track` | `due_soon` | `breached` | `null`
  (null si está resuelto/cerrado/cancelado o sin SLA). "Por vencer" = dentro de 4 h.
- Lista para el panel de escalamiento:
  `GET /support/tickets/sla?filter=all` (`breached` | `due_soon` | `all`), ordenada por vencimiento.

### Push / FCM (ya activo)
1. **Registrar token** del dispositivo:
   - Cliente: `POST /client/devices` · Staff: `POST /support/devices`
     ```json
     { "token": "<fcm_token>", "platform": "android" }   // android|ios
     ```
   - Baja: `DELETE /client/devices/{token}` / `DELETE /support/devices/{token}`
2. **Eventos que disparan push** (payload `data` siempre lleva `ticket_id` + `type`):

   | Evento | `type` | Destino |
   |--------|--------|---------|
   | Ticket asignado | `ticket_assigned` | técnico |
   | Cambio de estado | `ticket_status` | cliente / técnico |
   | Nuevo mensaje | `ticket_message` | la contraparte |
   | Recordatorio de visita | `appointment_reminder` | técnico + cliente |

   ```jsonc
   // ejemplo de payload data recibido por la app
   { "ticket_id": "4501", "type": "appointment_reminder" }
   ```
   La app debe enrutar al ticket usando `ticket_id` y diferenciar el comportamiento por `type`.
   El recordatorio se envía automáticamente ~60 min antes de `scheduled_at`.

### Historial de servicio del equipo
`GET /support/units/{id}/history`
```jsonc
{
  "unit": { /* SoldUnit */ },
  "tickets_count": 3,
  "timeline": [   // cronológico, más reciente primero
    { "type": "resolved", "at": "...", "ticket_code": "SOP-2026-0002",
      "technician": "Carlos Pérez", "diagnosis": "..." },
    { "type": "visit", "at": "...", "ticket_code": "SOP-2026-0002",
      "checkin_at": "...", "checkout_at": "...", "duration_minutes": 45, "work_done": "..." },
    { "type": "ticket_opened", "at": "...", "ticket_code": "SOP-2026-0002",
      "category": "falla", "subject": "..." }
  ]
}
```
Tipos de evento: `ticket_opened` · `visit` · `resolved`.

---

## Checklist de integración para la app

- [ ] Crear ticket con `service_type` y `service_address_id` (domicilio).
- [ ] Mostrar `client` y botón "Cómo llegar" con `service_address.latitude/longitude`.
- [ ] Vista de agenda (`/agenda`) y programación (`/schedule`).
- [ ] Flujo en sitio: check-in → registrar repuestos → reporte con firma → check-out.
- [ ] Mostrar/descargar `report_pdf_url` (acta) tras el reporte.
- [ ] Presupuesto: crear (staff) y aprobar/rechazar (cliente).
- [ ] Selector de técnico con filtros de especialidad/zona/disponibilidad.
- [ ] Registrar token FCM al login y manejar los 4 `type` de push.
- [ ] Panel/insignia de SLA con `sla_status` y lista `/sla`.
- [ ] Historial del equipo en la ficha de la unidad.
