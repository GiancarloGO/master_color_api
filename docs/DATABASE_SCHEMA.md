# Esquema de base de datos — Master Color API

Generado a partir de las 38 migraciones en `database/migrations/` (2026-08-27).
Motor: PostgreSQL (los `enum` de Laravel se implementan como `varchar` + `CHECK` constraint).

No se documentan aquí las tablas de infraestructura de Laravel sin valor de dominio:
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`.

El diagrama visual equivalente está en [`database_diagram.dbml`](../database_diagram.dbml) — pégalo en [dbdiagram.io](https://dbdiagram.io).

## Índice

- [Autenticación y usuarios](#autenticación-y-usuarios)
- [Clientes y direcciones](#clientes-y-direcciones)
- [Catálogo e inventario](#catálogo-e-inventario)
- [Ventas: órdenes y pagos](#ventas-órdenes-y-pagos)
- [Garantía: unidades vendidas](#garantía-unidades-vendidas)
- [Soporte técnico (tickets)](#soporte-técnico-tickets)
- [Auditoría y chatbot](#auditoría-y-chatbot)
- [Relaciones polimórficas simples](#relaciones-polimórficas-simples)

---

## Autenticación y usuarios

### `roles`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| name | varchar | |
| description | varchar | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `users` (staff: admin, vendedor, técnico...)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| name | varchar | |
| email | varchar unique | |
| email_verified_at | timestamp null | |
| password | varchar | |
| token_version | varchar default `'0'` | invalida tokens JWT emitidos |
| role_id | bigint FK → `roles.id` | |
| is_active | boolean default true | |
| specialties | json null | categorías de ticket que atiende el técnico |
| coverage_zones | json null | distritos/zonas que cubre |
| is_available | boolean default true | disponible para nuevas asignaciones de visita |
| dni | varchar unique | |
| phone | varchar null | |
| remember_token | varchar null | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `password_reset_tokens` y `password_resets`
Dos tablas paralelas para reset de contraseña de staff: `password_reset_tokens` (scaffold estándar de Laravel, PK en `email`) y `password_resets` (agregada 2026-02-01, `email` indexado no único). Mismo propósito, implementación duplicada — candidato a unificar.

| Columna | Tipo |
|---|---|
| email | varchar |
| token | varchar |
| created_at | timestamp null |

### `client_password_resets`
Mismo patrón que las anteriores, para clientes (`clients`) en vez de staff.

### `personal_access_tokens` (Laravel Sanctum)
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tokenable_type / tokenable_id | morphs | `App\Models\User` o `App\Models\Client` |
| name | varchar | |
| token | varchar(64) unique | |
| abilities | text null | |
| last_used_at / expires_at | timestamp null | |
| created_at / updated_at | timestamp | |

### `device_tokens`
Tokens FCM para push notifications, dueño polimórfico (staff/técnico o cliente).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| tokenable_type / tokenable_id | morphs | `App\Models\User` o `App\Models\Client` |
| token | varchar unique | |
| platform | enum: `android`, `ios` | |
| last_used_at | timestamp null | |
| created_at / updated_at | timestamp | |

---

## Clientes y direcciones

### `clients`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| name | varchar | |
| email | varchar | (no `unique` a nivel de columna) |
| password | varchar | |
| client_type | enum: `individual`, `company` | |
| identity_document | varchar unique | |
| document_type | enum: `DNI`, `RUC`, `CE`, `PASAPORTE` | |
| email_verified_at | timestamp null | |
| is_test | boolean default false | permite pago simulado sin pasar por MercadoPago |
| verification_token | varchar null | |
| token_version | varchar default `'0'` | |
| phone | varchar null | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `addresses`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| client_id | bigint FK → `clients.id`, null, `ON DELETE CASCADE` | |
| address_full / district / province / department / postal_code / reference | varchar | |
| latitude / longitude | decimal(10,7) null | para navegación del técnico y pin en mapa |
| is_main | boolean default false | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

---

## Catálogo e inventario

### `categories`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| name | varchar unique | |
| slug | varchar unique | |
| active | boolean default true | índice |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `products`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| name | varchar | índice compuesto con `category` |
| sku | varchar unique | índice |
| image_url | varchar | |
| barcode | varchar unique | índice |
| brand | varchar | índice |
| description | text | |
| presentation | varchar | |
| category | varchar | **legado** (slug); se mantiene por compatibilidad con app móvil / OpenAPI; índice |
| unidad | varchar | |
| default_warranty_months | smallint default 0 | 0 = sin garantía formal |
| category_id | bigint FK → `categories.id`, null | relación de primera clase, reemplaza gradualmente a `category` |
| user_id | bigint FK → `users.id` | quién lo creó |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `stocks`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| product_id | bigint FK → `products.id` unique, `ON DELETE CASCADE` | relación 1:1 con `products` |
| quantity / min_stock / max_stock | bigint default 0 | |
| purchase_price / sale_price | decimal(10,2) | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `stock_movements`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| movement_type | enum: `entrada`, `salida`, `ajuste`, `devolucion` | |
| reason | varchar | |
| user_id | bigint FK → `users.id` | |
| order_id | bigint FK → `orders.id`, null | orden de venta origen; reemplaza el matching frágil por `voucher_number LIKE 'VENTA-{id}-%'` |
| voucher_number | varchar null | |
| canceled_at | timestamp null | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `details_movements`
Líneas de detalle de un `stock_movement` (uno o varios productos afectados por el mismo movimiento).

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| stock_movement_id | bigint FK → `stock_movements.id`, `ON DELETE CASCADE` | |
| stock_id | bigint FK → `stocks.id`, `ON DELETE CASCADE` | |
| quantity | int | |
| unit_price | decimal(10,2) null | |
| previous_stock / new_stock | int | snapshot antes/después del movimiento |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

---

## Ventas: órdenes y pagos

### `orders`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| user_id | bigint FK → `users.id` | vendedor/staff que la registró |
| client_id | bigint FK → `clients.id` | |
| delivery_address_id | bigint FK → `addresses.id` | |
| subtotal | decimal(10,2) | |
| shipping_cost | decimal(10,2) default 0 | |
| discount | decimal(10,2) default 0 | |
| status | enum: `pendiente_pago`, `pendiente`, `confirmado`, `procesando`, `enviado`, `entregado`, `cancelado`, `pago_fallido` (default `pendiente_pago`) | |
| codigo_payment | varchar null | |
| observations | text null | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `order_details`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| product_id | bigint FK → `products.id` | |
| order_id | bigint FK → `orders.id`, `ON DELETE CASCADE` | |
| quantity | int | |
| unit_price / subtotal | decimal(10,2) | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `payments`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| order_id | bigint FK → `orders.id`, `ON DELETE CASCADE` | |
| payment_method | enum: `Efectivo`, `Tarjeta`, `Yape`, `Plin`, `TC`, `MercadoPago` (default `MercadoPago`) | |
| payment_code | varchar null | |
| amount | decimal(10,2) | |
| currency | varchar(3) default `PEN` | |
| status | enum: `pending`, `approved`, `rejected`, `cancelled`, `refunded` (default `pending`) | |
| external_id | varchar null | ID de pago de MercadoPago |
| external_response | json null | respuesta completa de MercadoPago |
| document_type | enum: `Boleta`, `Factura`, `Ticket`, `NC` (default `Ticket`) | |
| nc_reference | varchar null | |
| observations | text null | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

---

## Garantía: unidades vendidas

### `sold_units`
Unidad física (serializada o no) en manos de un cliente, origen para tickets de soporte/garantía.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| client_id | bigint FK → `clients.id` | |
| product_id | bigint FK → `products.id` | |
| order_id | bigint FK → `orders.id`, null, `ON DELETE SET NULL` | origen: orden online |
| order_detail_id | bigint FK → `order_details.id`, null, `ON DELETE SET NULL` | |
| serial_number | varchar null | índice; opcional (productos no serializados no lo tienen) |
| purchase_date | date | |
| warranty_months | smallint default 0 | |
| warranty_expires_at | date null | |
| registration_source | enum: `order`, `manual` (default `order`) | registro manual del cliente vs. generado por la orden |
| proof_file_path | varchar null | comprobante para registro manual |
| status | enum: `activa`, `en_servicio`, `baja` (default `activa`) | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

---

## Soporte técnico (tickets)

### `support_tickets`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| code | varchar unique | código legible del ticket |
| client_id | bigint FK → `clients.id` | |
| sold_unit_id | bigint FK → `sold_units.id`, null, `ON DELETE SET NULL` | |
| product_id | bigint FK → `products.id`, null, `ON DELETE SET NULL` | |
| category | enum: `garantia`, `instalacion`, `falla`, `consulta`, `otro` | |
| priority | enum: `baja`, `media`, `alta`, `urgente` (default `media`) | |
| subject | varchar(150) | |
| description | text | |
| status | enum: `abierto`, `asignado`, `en_proceso`, `en_espera_cliente`, `en_espera_aprobacion`, `resuelto`, `cerrado`, `cancelado` (default `abierto`) | `en_espera_aprobacion` se agregó después vía `ALTER ... CHECK` |
| assigned_user_id | bigint FK → `users.id`, null, `ON DELETE SET NULL` | técnico asignado |
| channel | enum: `app`, `web`, `telefono` (default `app`) | |
| service_type | enum: `remoto`, `domicilio`, `taller` (default `remoto`) | distingue el flujo de atención |
| service_address_id | bigint FK → `addresses.id`, null, `ON DELETE SET NULL` | solo cuando `service_type = domicilio` |
| scheduled_at | timestamp null | programación de la visita |
| scheduled_window_minutes | smallint null | |
| reminder_sent_at | timestamp null | evita reenvío del recordatorio push |
| is_warranty_covered | boolean null | |
| sla_due_at | timestamp null | |
| first_response_at | timestamp null | |
| resolved_at / closed_at | timestamp null | |
| rating | tinyint null | calificación del cliente |
| rating_comment | text null | |
| diagnosis | text null | |
| parts_used | text null | resumen textual (ver también `ticket_parts` para el detalle estructurado) |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `ticket_messages`
Hilo de conversación del ticket (cliente ↔ staff), con notas internas.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| ticket_id | bigint FK → `support_tickets.id`, `ON DELETE CASCADE` | |
| author_type | enum: `client`, `user` | ver [relaciones polimórficas simples](#relaciones-polimórficas-simples) |
| author_id | bigint | |
| author_name | varchar | snapshot del nombre del autor |
| body | text | |
| is_internal | boolean default false | nota interna de staff, no visible para el cliente |
| read_at | timestamp null | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `ticket_attachments`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| ticket_id | bigint FK → `support_tickets.id`, `ON DELETE CASCADE` | |
| ticket_message_id | bigint FK → `ticket_messages.id`, null, `ON DELETE SET NULL` | |
| file_path | varchar | |
| file_type / original_name | varchar null | |
| uploaded_by_type | enum: `client`, `user` | |
| uploaded_by_id | bigint | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `ticket_status_history`
Auditoría de cambios de estado del ticket.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| ticket_id | bigint FK → `support_tickets.id`, `ON DELETE CASCADE` | índice |
| from_status | varchar null | |
| to_status | varchar | |
| changed_by_type | enum: `client`, `user`, `system` | |
| changed_by_id | bigint null | |
| changed_by_name | varchar | |
| note | text null | |
| created_at / updated_at | timestamp | |

### `ticket_parts`
Repuestos consumidos por un ticket, vinculados al inventario.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| ticket_id | bigint FK → `support_tickets.id`, `ON DELETE CASCADE` | índice |
| stock_id | bigint FK → `stocks.id` | |
| quantity | int (unsigned) | |
| unit_cost | decimal(10,2) null | |
| stock_movement_id | bigint FK → `stock_movements.id`, null, `ON DELETE SET NULL` | movimiento de salida generado; permite revertir si se elimina el repuesto |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `ticket_quotes`
Cotizaciones/presupuestos de servicio fuera de garantía. Una fila por versión; la vigente es la más reciente.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| ticket_id | bigint FK → `support_tickets.id`, `ON DELETE CASCADE` | índice compuesto con `status` |
| labor_cost / parts_cost / total | decimal(10,2) default 0 | |
| currency | varchar(3) default `PEN` | |
| status | enum: `pendiente`, `aprobado`, `rechazado` (default `pendiente`) | |
| note | text null | |
| created_by_user_id | bigint FK → `users.id`, null, `ON DELETE SET NULL` | |
| decided_at | timestamp null | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

### `ticket_visits`
Visita en sitio del técnico: check-in/check-out geolocalizado y acta de servicio.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| ticket_id | bigint FK → `support_tickets.id`, `ON DELETE CASCADE` | índice |
| technician_id | bigint FK → `users.id` | |
| checkin_at / checkout_at | timestamp null | |
| checkin_latitude / checkin_longitude | decimal(10,7) null | |
| checkout_latitude / checkout_longitude | decimal(10,7) null | |
| work_done | text null | |
| client_signed_name | varchar null | |
| signature_path | varchar null | firma de conformidad |
| report_pdf_path | varchar null | acta en PDF |
| reported_at | timestamp null | |
| created_at / updated_at | timestamp | |
| deleted_at | timestamp null | soft delete |

---

## Auditoría y chatbot

### `audit_logs`
| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| actor_type | enum: `staff`, `client`, `system` | |
| actor_id | bigint null | |
| actor_name | varchar null | |
| action | varchar | índice |
| entity_type | varchar null | índice compuesto con `entity_id` |
| entity_id | bigint null | |
| old_values / new_values | json null | |
| ip_address | varchar(45) null | |
| user_agent | varchar(512) null | |
| metadata | json null | |
| created_at | timestamp (`useCurrent`) | índice; **sin `updated_at`** (tabla append-only) |

### `chat_logs`
Historial del chatbot de la app/web.

| Columna | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| session_id | uuid | índice |
| role | enum: `user`, `assistant` | |
| message | text | |
| ip_address | varchar(45) null | |
| created_at | timestamp (`useCurrent`) | índice; **sin `updated_at`** |

---

## Relaciones polimórficas simples

Varias tablas usan un par `*_type` + `*_id` para referenciar indistintamente a `users` (staff/técnico) o `clients`, sin usar `morphs()` de Eloquent (por eso no aparecen como FK reales en el diagrama):

| Tabla | Columnas | Valores de `*_type` |
|---|---|---|
| `ticket_messages` | `author_type` / `author_id` | `client` → `clients.id`, `user` → `users.id` |
| `ticket_attachments` | `uploaded_by_type` / `uploaded_by_id` | `client` → `clients.id`, `user` → `users.id` |
| `ticket_status_history` | `changed_by_type` / `changed_by_id` | `client`, `user`, o `system` (sin id) |
| `audit_logs` | `actor_type` / `actor_id` | `staff` → `users.id`, `client` → `clients.id`, o `system` (sin id) |

Las que sí usan `morphs()` de Eloquent (`tokenable_type` + `tokenable_id`, con namespace completo `App\Models\...`) son `personal_access_tokens` y `device_tokens`.
