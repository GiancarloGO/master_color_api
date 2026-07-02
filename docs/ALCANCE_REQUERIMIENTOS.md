# Alcance y Documentación de Requerimientos — Master Color

**Fecha:** 2026-07-01
**Proyectos involucrados:**
- `master_color_api` — Backend Laravel 12 (PHP 8.2)
- `master_color_frontend` — Panel web Vue 3 + PrimeVue 4 (Sakai)
- `master_color_appmovil` — App móvil Flutter (solo si aplica)

Este documento consolida el estado actual del sistema, el análisis técnico y el
alcance acordado para cuatro requerimientos: **saneamiento de logs en BD**,
**gestión de categorías**, **validación de devolución de stock en anulaciones**
y **sincronización de fechas de compra en la app móvil**.

---

## Estado general (actualizado: 2026-07-01)

| # | Requerimiento | Estado | Notas |
|---|---|---|---|
| **1** | Saneamiento de logs en BD | ✅ **Completado** | Chatbot no escribe en BD por defecto; `logs:prune` diario; purga real ejecutada en dev (−144 `chat_logs`). Pendiente opcional: reducir eventos de auditoría. |
| **2** | Gestión de Categorías | ✅ **Completado** | Entidad + CRUD + UI + landing dinámica + OpenAPI. 27/27 productos vinculados. |
| **3** | Devolución de stock en anulación | ✅ **Completado** | `order_id` FK + backfill (16/16), rollback robusto, `lockForUpdate`, 8 tests en verde. |
| **4** | Fechas de compra (app móvil) | ✅ **Código listo** | Fix `.toLocal()` aplicado y auditado. Pendiente: validación manual en dispositivo (borde de medianoche). |

**Leyenda:** ✅ Completado · 🟡 En progreso · 📋 Solo documentado

**Pendientes transversales (no bloqueantes):**
- Req.1: auditar los ~55 puntos de escritura de `audit_logs` para reducir volumen
  (opcional); confirmar retención (180 días) con negocio antes de purgar en prod.
- Req.2: actualizar spec móvil solo si en el futuro consume categorías (hoy no).
- Req.4: prueba en dispositivo con orden creada cerca de medianoche.
- Aparte: los tests de soporte/email están rotos por causas **preexistentes**
  (APP_URL con `/api`, falta `ClientFactory`, fixtures desfasados) — no afectan
  a estos requerimientos.

---

## Requerimiento 1 — Saneamiento del registro de logs en base de datos

> *Revisar el registro de logs para evitar que se almacenen en la base de datos,
> ya que esto podría generar saturación y afectar el rendimiento a futuro.*

### Estado actual

El sistema persiste **tres orígenes de logs** en la base de datos, ninguno con
política de retención ni purga:

| Origen | Tabla / Destino | Escritura | Riesgo |
|---|---|---|---|
| **Chatbot** (`ChatbotController`) | `chat_logs` | 2 filas por cada intercambio (mensaje usuario + respuesta) | **Alto** — crecimiento por tráfico público, sin límite |
| **Auditoría** (`AuditService`) | `audit_logs` | ~55 puntos de escritura en controladores/servicios; guarda JSON completo de `old_values`/`new_values` + `user_agent` + `metadata` | **Medio-Alto** — filas grandes, crecimiento constante |
| **Aplicación / errores** (`Log::` de Laravel) | Configurable | Depende del canal en `config/logging.php` | **Bajo-Medio** — verificar que no escriba a BD |

**Hallazgos clave:**
- `database/migrations/2026_05_09_000001_create_chat_logs_table.php` y
  `2026_05_08_000001_create_audit_logs_table.php` crean tablas sin TTL.
- El único comando de limpieza existente es `CleanOrphanPayments` (pagos, no logs).
- `audit_logs` está bien indexada (`actor`, `entity`, `action`, `created_at`),
  pero sin retención los índices también crecen sin control.
- `ChatLog::create()` en `ChatbotController::message()` escribe de forma síncrona
  en el flujo de respuesta al usuario.

> **Nota:** El requerimiento menciona "Pestaña Categorías" como encabezado, pero
> no existe tal pestaña hoy. Se interpreta como un agrupador del documento origen;
> el trabajo de logs es transversal, no ligado a categorías.

### Alcance acordado

Se abordan **los tres orígenes**:

**Backend (`master_color_api`)**
1. **`chat_logs`**
   - Redirigir el registro del chatbot a un **canal de archivo** (o hacerlo
     opcional vía `.env`, p. ej. `CHATBOT_LOG_TO_DB=false`).
   - Si se conserva en BD por analítica, mover la escritura a cola/asíncrona y
     añadir purga.
2. **`audit_logs`**
   - Definir **política de retención** (p. ej. conservar N días/meses).
   - Crear comando artesanal `logs:prune` (o `audit:prune`) + programación en
     `routes/console.php` (scheduler).
   - Revisar y **reducir eventos de bajo valor** / recortar tamaño de
     `old_values`/`new_values`/`metadata` cuando no aporten.
3. **`Log::` de Laravel**
   - Verificar `config/logging.php`: confirmar que el canal por defecto sea
     `stack`/`daily` (archivo) y **no** un canal a BD.

**Frontend (`master_color_frontend`)**
- `AuditLogs.vue` sigue funcionando; si se aplica retención, documentar en la UI
  que solo se muestran los últimos N días (opcional).

### Tareas
- [x] Parametrizar destino de `chat_logs` (archivo vs BD): flag
      `CHATBOT_PERSIST_LOGS` (default **false**) en `config/chatbot.php`. El
      chatbot ahora registra al canal de archivo `chatbot` (`config/logging.php`)
      y solo persiste en BD si el flag está activo (`ChatbotController::logConversation`).
- [x] Comando `logs:prune` con retención configurable (`config/audit.php` →
      `AUDIT_LOG_RETENTION_DAYS`, default 180; `chatbot.log_retention_days`,
      default 30) + registrado en el scheduler (`routes/console.php`, diario 03:30).
      Borra en lotes; `retention_days <= 0` desactiva la purga. Soporta `--dry-run`.
- [x] Verificar `config/logging.php`: canal por defecto `stack`→`single`
      (archivo), **sin** canal a BD. Confirmado.
- [~] Auditar los ~55 call sites de auditoría y marcar eventos prescindibles:
      **no abordado** en esta iteración; la purga por retención mitiga el
      crecimiento. Pendiente si se requiere reducir volumen de escritura.
- [x] Documentar variables nuevas en `.env.example`.

### Criterios de aceptación
- [x] El chatbot deja de escribir en BD por defecto (solo si se habilita
      explícitamente con `CHATBOT_PERSIST_LOGS=true`).
- [x] Existe purga automática programada (`logs:prune`, diaria) de `audit_logs`
      y `chat_logs` con retención definida.
- [x] Se documenta la política de retención y cómo ajustarla (`.env.example` +
      configs comentadas).

### Verificación
- `logs:prune --dry-run` sobre datos reales: 0 `audit_logs` > 180 días,
  144 `chat_logs` > 30 días detectados correctamente. No se ejecutó el borrado
  destructivo (lo hará el scheduler / manualmente).
- `schedule:list` muestra `logs:prune` programado (03:30 diario).

### Riesgos
- Purgar auditoría puede tener implicaciones legales/contables → el periodo por
  defecto (180 días) es configurable; confirmar con el negocio antes de la
  primera purga en producción.

---

## Requerimiento 2 — Gestión de Categorías (registro manual)

> *Agregar un botón que permita registrar nuevas categorías de forma manual.*

### Estado actual

Las categorías **no son una entidad**. Hoy son:
- Un **campo `string`** en `products` (`app/Models/Product.php:22` → `'category'`),
  validado en `ProductStoreRequest`/`ProductUpdateRequest` como
  `required|string|max:255`.
- Una **lista fija hardcodeada de 6 opciones** en
  `master_color_frontend/src/views/products/ProductForm.vue:37`:
  `impresoras, tintas, toners, papel, repuestos, accesorios`.
- Filtro/búsqueda por texto sobre `product.category` en `Products.vue` y columna
  en `ProductsTable.vue`. No hay tabla, modelo, ni endpoint de categorías.

### Enfoque acordado: **Entidad Categorías + CRUD**

Se crea una entidad de primera clase con gestión propia y botón de alta manual.

**Backend (`master_color_api`)**
- Migración `categories` (`id`, `name`, `slug`, `active`, timestamps).
- Modelo `Category` + relación `Product belongsTo Category`.
- Migración de datos: sembrar las 6 categorías actuales y **migrar** el campo
  `products.category` (string) a `category_id` (FK). Mantener compatibilidad
  temporal si hay consumidores externos (app móvil / OpenAPI).
- `CategoryController` con CRUD (`index`, `store`, `update`, `destroy`) +
  `CategoryResource` + `CategoryStore/UpdateRequest`.
- Rutas en `routes/api.php` protegidas por rol (admin/almacén).
- Actualizar `ProductService` (búsqueda/filtro por categoría vía relación) y
  `ProductResource` para exponer la categoría.

**Frontend (`master_color_frontend`)**
- Nueva vista/pestaña **Categorías** (`src/views/categories/`) con tabla y botón
  **"Nueva categoría"** (modal de alta/edición), store Pinia `categories.js`,
  y funciones en `src/api/index.js`.
- `ProductForm.vue`: reemplazar el array hardcodeado por opciones cargadas del
  endpoint; permitir seleccionar la categoría (con opción de crear al vuelo si se
  desea, reutilizando el endpoint).
- Ruta protegida en `src/router/index.js` + entrada en el menú lateral.

**App móvil (`master_color_appmovil`)** — solo si consume categorías: ajustar el
contrato de producto tras la migración a `category_id`.

### Tareas
- [x] Migración + modelo `Category` + relación con `Product`
      (`productCategory` para no colisionar con la columna string `category`).
- [x] Seeder/migración de datos desde `products.category` (6 categorías
      sembradas; 27/27 productos vinculados, 0 huérfanos — verificado).
- [x] CRUD API (`CategoryController`, `CategoryStore/UpdateRequest`,
      `CategoryResource`, `apiResource` en `api.php` bajo `admin.only`).
- [x] Ajustar `ProductService` (resuelve `category_id` desde el slug en
      create/update) y `ProductResource` (expone `category_id` y `category_name`).
- [x] Vista Categorías + botón "Nueva Categoría" + store `categories.js` +
      funciones en `api/index.js` + ruta y entrada en el menú lateral.
- [x] Reemplazar dropdown hardcodeado en `ProductForm.vue` por categorías
      dinámicas del backend.
- [x] Eliminar categorías hardcodeadas de la landing (`views/store/Home.vue`):
      el filtro lateral ahora se deriva de los productos (slug + `category_name`),
      con iconos por slug y fallback `pi-tag` para categorías nuevas. El resource
      público de producto expone `category_name` (con eager-load `productCategory`
      para evitar N+1).
- [x] Documentar OpenAPI: nuevo `docs/openapi/catalog-api.yaml` con el CRUD de
      categorías (staffAuth/admin) + README actualizado. El contrato móvil
      (`support-api.yaml`) no requiere cambios: no expone categorías de producto.

### Criterios de aceptación
- Un administrador puede crear/editar/desactivar categorías desde la UI.
- El formulario de producto lista categorías dinámicas desde el backend.
- Los productos existentes conservan su categoría tras la migración.

### Riesgos
- La migración `string → FK` debe contemplar valores fuera de las 6 opciones y
  registros huérfanos → normalizar antes de migrar.

---

## Requerimiento 3 — Validación de devolución de stock al anular ventas

> *Revisar y validar el proceso de devolución de stock cuando una venta sea
> anulada, asegurando que las cantidades se restituyan correctamente al inventario.*

### Estado actual (el flujo YA existe)

**Descuento de stock por venta** — `StockMovementService::processOrderStockReduction()`:
- Crea un `StockMovement` tipo `salida` con `voucher_number = "VENTA-{orderId}-{ts}"`.
- Es **idempotente**: si ya existe una `salida` activa para la orden, no vuelve a
  descontar (protege contra webhooks repetidos de MercadoPago).
- Registra `DetailMovement` con `previous_stock`/`new_stock` y decrementa `stock`.

**Anulación / rollback:**
- **Admin** (`OrderController`): al detectar transición a `cancelado`
  (`OrderController.php:143` `$isCancelling`) llama a
  `PaymentService::rollbackOrderStock()`.
- **Cliente** (`ClientOrderController::cancelOrder`): si el estado previo era
  `pendiente` o `confirmado`, también invoca `rollbackOrderStock()` dentro de una
  transacción.
- `rollbackStockIfNeeded()` busca las `salida` activas por
  `voucher_number LIKE "VENTA-{orderId}-%"` y por cada una llama
  `cancelMovement()`, que crea un movimiento inverso `entrada` (incrementa stock)
  y marca el original con `canceled_at`. Las excepciones se propagan para revertir
  la transacción y **no** dejar la orden como cancelada si el stock no se pudo
  devolver.

**Valoración:** el mecanismo es **funcionalmente correcto**. Este requerimiento es
de **validación y endurecimiento**, no de construcción desde cero.

### Puntos a validar / endurecer (alcance)

1. **Acoplamiento por string de voucher.** El rollback depende de
   `voucher_number LIKE "VENTA-{orderId}-%"`. No hay FK que relacione
   `StockMovement` ↔ `Order`. Riesgo de falsos negativos/positivos si cambia el
   formato del voucher. → Evaluar añadir `order_id` (nullable FK) al movimiento.
2. **Consistencia entre estados que descuentan vs. que revierten.** Confirmar qué
   estados descontaron stock realmente y que la lógica de anulación revierte solo
   en esos casos (evitar devolver stock que nunca se descontó, y viceversa).
3. **Doble anulación / concurrencia.** Verificar guardas: `$isCancelling` (admin)
   y `canceled_at` en `cancelMovement` previenen doble reverso; validar bajo
   concurrencia (lock/transacción).
4. **Múltiples movimientos de venta.** `rollbackStockIfNeeded` ya itera todas las
   `salida` activas; confirmar con caso de duplicado histórico.
5. **Cobertura de pruebas.** Añadir tests de integración para: venta→cancelación
   restituye exacto; cancelación sin stock descontado no falla; idempotencia;
   fallo de devolución revierte la orden.

### Tareas
- [x] Escribir pruebas que cubran los escenarios clave (8 tests en
      `tests/Feature/OrderStockRollbackTest.php`, todos en verde): idempotencia,
      restitución exacta, duplicados heredados, anulación sin descuento, enlace
      por `order_id`, idempotencia por `order_id`, doble anulación y **fallo de
      devolución que revierte la orden**.
- [x] Añadir `order_id` (FK nullable) a `stock_movements`
      (`2026_07_01_000003_add_order_id_to_stock_movements_table.php`) con backfill
      desde el patrón `VENTA-{id}-` (16/16 movimientos enlazados — verificado).
      El rollback y la idempotencia ahora usan `order_id`; el `LIKE` de voucher
      queda solo como respaldo para datos legados.
- [x] Auditar la matriz de transiciones (`OrderController::isValidStatusTransition`)
      vs. estados que descuentan stock: **consistente**. El stock se descuenta al
      aprobar el pago (`pendiente_pago`→`pendiente`) y permanece en
      `confirmado/procesando/enviado/entregado`; no se descuenta en
      `pendiente_pago` ni `pago_fallido`. El rollback es no-op seguro cuando no
      hubo descuento. Guarda de doble anulación confirmada (`$isCancelling` +
      `canceled_at`) y reforzada con `lockForUpdate()` para anulaciones concurrentes.
- [x] Documentar el flujo venta→descuento→anulación→devolución (esta sección).

### Criterios de aceptación
- [x] Anular una venta que descontó stock restituye **exactamente** las cantidades.
- [x] Anular una venta que nunca descontó stock no altera el inventario ni falla.
- [x] Si la devolución de stock falla, la orden **no** queda marcada como cancelada.
- [x] Existe cobertura de pruebas automatizada del flujo.

### Flujo (referencia)
1. **Descuento**: pago aprobado → `updateOrderStatus('approved')` →
   `processOrderStockReduction` crea `salida` con `order_id` y voucher
   `VENTA-{id}-{ts}` (idempotente por `order_id`).
2. **Anulación (admin)**: `OrderController::updateStatus` detecta transición a
   `cancelado` → `rollbackOrderStock`.
3. **Anulación (cliente)**: `ClientOrderController::cancelOrder` revierte si el
   estado previo era `pendiente`/`confirmado`.
4. **Devolución**: `rollbackStockIfNeeded` busca `salida` activas por `order_id`
   (con `lockForUpdate`) y por cada una `cancelMovement` crea el reverso `entrada`
   y marca `canceled_at`. Las excepciones se propagan → la transacción del
   invocador revierte y la orden NO queda cancelada.

### Riesgos
- El backfill de `order_id` sobre movimientos históricos ya se ejecutó; el `LIKE`
  de voucher permanece como respaldo por si hubiera datos sin backfillear.

---

## Requerimiento 4 — App Móvil: Sincronización de fechas de compra

> *Revisar la visualización de las fechas de compra en la aplicación móvil.
> Actualmente, las fechas mostradas no coinciden con las fechas de las órdenes
> generadas desde la plataforma.*

### Estado actual y causa raíz (diagnosticada)

El API almacena y serializa las fechas en **UTC**, mientras que la app móvil las
muestra **sin convertir a la zona horaria local**, a diferencia de la web:

| Capa | Comportamiento | Resultado |
|---|---|---|
| **API** (`config/app.php:68`) | `timezone => 'UTC'`; Laravel serializa `created_at` como ISO8601 con sufijo `Z` (ej. `2026-07-01T01:00:00.000000Z`) | UTC |
| **Web** (`OrderDetailModal.vue:80`) | `new Date(str).toLocaleString('es-PE', …)` → JS **convierte a la zona local** del navegador (Perú UTC−5) | Fecha local ✅ |
| **Móvil** (`order_presentation.dart:67`) | `DateFormat('dd/MM/yyyy').format(d)` sobre un `DateTime` en UTC, **sin `.toLocal()`** | Fecha en UTC ❌ |

**Efecto:** en compras realizadas por la tarde/noche en Perú (que en UTC ya
corresponden al día siguiente), la app muestra un día **adelantado** respecto a la
web y a la fecha real de la orden.

**Evidencia de que es un bug aislado:** el resto de la app ya usa `.toLocal()` de
forma consistente antes de formatear fechas — p. ej.
`tickets_screen.dart:422`, `units/unit_presentation.dart:12`,
`staff_units/unit_history_screen.dart:164`, `agenda_screen.dart:184`,
`sla_screen.dart:118`. Solo `formatOrderDate` en el módulo de órdenes omite la
conversión, desviándose de la convención establecida.

### Alcance acordado

**App móvil (`master_color_appmovil`)**
- Corregir `formatOrderDate` en
  `lib/features/orders/order_presentation.dart` para convertir a hora local antes
  de formatear:
  ```dart
  String formatOrderDate(DateTime? d) {
    if (d == null) return '—';
    return DateFormat('dd/MM/yyyy').format(d.toLocal());
  }
  ```
- Verificar que no existan otras fechas de órdenes/compras mostradas sin
  `.toLocal()` (revisar `order_detail_screen.dart`, `orders_screen.dart` y
  `OrderDetailResource.order_created_at` si se consume).

**Backend / Web:** sin cambios. La web ya muestra correctamente en hora local; el
backend en UTC es la práctica correcta (la conversión es responsabilidad del
cliente).

### Tareas
- [x] Aplicar `.toLocal()` en `formatOrderDate` (`order_presentation.dart:67`).
- [x] Auditar el módulo de órdenes móvil por otras fechas sin conversión —
      único campo de fecha es `Order.createdAt`, mostrado solo vía
      `formatOrderDate` en `orders_screen.dart:208` y `order_detail_screen.dart:62`;
      `OrderDetail` no tiene fechas. Sin otros puntos pendientes.
- [ ] Validar en dispositivo con orden creada cerca de medianoche (borde de día).

### Criterios de aceptación
- La fecha de compra en la app coincide con la mostrada en la web para la misma
  orden, incluyendo casos límite alrededor de la medianoche.

### Riesgos
- Bajo. Cambio de una línea alineado con la convención existente; sin impacto en
  backend ni en datos almacenados.

---

## Resumen de esfuerzo relativo

| Req. | Tipo de trabajo | Backend | Frontend | Móvil | Complejidad |
|---|---|---|---|---|---|
| 1 — Logs | Saneamiento / infra | Alto | Bajo | — | Media |
| 2 — Categorías | Feature nueva (entidad + CRUD) | Alto | Alto | Bajo* | Alta |
| 3 — Devolución stock | Validación + hardening + tests | Medio | — | — | Media |
| 4 — Fechas móvil | Corrección de bug (zona horaria) | — | — | Bajo | Baja |

\* Solo si la app móvil consume categorías.

## Decisiones registradas
- **Req.1:** se sanean los tres orígenes (`chat_logs`, `audit_logs`, `Log::`).
- **Req.2:** se implementa **entidad Categorías + CRUD** (no texto libre).
- **Req.3:** el flujo existe; alcance = validación, endurecimiento y pruebas.

## Pendientes por confirmar con el negocio
- Periodo de retención de `audit_logs` antes de purgar.
- Si `chat_logs` debe conservarse (analítica) o eliminarse por completo.
- Si la app móvil consume el campo `category` (impacta la migración del Req.2).
