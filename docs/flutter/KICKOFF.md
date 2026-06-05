# App de Soporte Técnico — Documento de arranque (Flutter)

Guía para iniciar el proyecto **Flutter separado** que consume el backend Laravel de
MasterColor. El contrato de la API está en [`../openapi/support-api.yaml`](../openapi/support-api.yaml).

> Estado del backend: MVP **completo** (auth, unidades, garantías, tickets, push/email,
> métricas, asignación de serie). 37 tests verdes. La app puede arrancar ya.

---

## 1. Alcance y arquitectura de la app

- **Una sola app** con navegación **gateada por rol**: cliente final y técnico/staff.
  Comparten login, capa de red y push; cambian las pantallas según el guard.
- Dos audiencias = dos JWT distintos (guards `client` y `users`). El rol se decide en login.

| Rol | Login | Endpoints base |
|-----|-------|----------------|
| Cliente | `POST /client/auth/login` | `client/units`, `client/support/tickets`, `client/devices` |
| Técnico/Staff | `POST /auth/login` | `support/tickets`, `support/units`, `support/metrics`, `support/devices` |

---

## 2. Hechos del backend que la app debe conocer

**Base URL**: `http://localhost:8000/api` (dev) · prod por definir.

**Envelope de respuesta** (TODAS las respuestas):
```json
{ "success": true, "message": "...", "status": 200, "data": { }, "errors": null }
```
- Error: `success:false`, `data:[]`, `errors` con detalle.
- Validación (422): `errors` es `{ "campo": ["mensaje", ...] }`.

**Autenticación**: JWT Bearer en header `Authorization: Bearer <access_token>`.
Respuesta de login/refresh trae:
```json
{ "data": { "access_token": "...", "token_type": "bearer", "expires_in": 3600 } }
```
- Refresh cliente: `POST /client/auth/refresh` · Refresh staff: `POST /auth/refresh`
- Logout cliente: `POST /client/auth/logout` · Logout staff: `POST /auth/logout`
- El cliente debe tener el email verificado para iniciar sesión.

**Enums clave** (para selects/validación en UI):
- Categoría ticket: `garantia, instalacion, falla, consulta, otro`
- Prioridad: `baja, media, alta, urgente`
- Estado ticket: `abierto, asignado, en_proceso, en_espera_cliente, resuelto, cerrado, cancelado`
- Estado unidad: `activa, en_servicio, baja`
- Plataforma device: `android, ios`

**Listas**: los index están paginados (`?page=`, `?per_page=`, default 15). Confirmar la
forma exacta de la metadata de paginación al integrar (inspeccionar una respuesta real).

---

## 3. Estructura de proyecto recomendada

```
lib/
  main.dart
  app.dart                      # MaterialApp + router + theme
  core/
    config/env.dart             # base url por flavor
    network/
      api_client.dart           # Dio + interceptors (auth, refresh, logging)
      api_response.dart         # parseo del envelope {success,data,errors}
      api_exception.dart
    storage/secure_storage.dart # tokens (flutter_secure_storage)
    auth/
      auth_repository.dart      # login/refresh/logout, rol activo
      auth_controller.dart      # estado de sesión (Riverpod)
      session.dart              # token + rol (client|staff)
    push/
      push_service.dart         # FCM init, registro de token, manejo de mensajes
    router/app_router.dart      # rutas gateadas por rol (go_router)
  api/                          # CLIENTE GENERADO desde OpenAPI (no editar a mano)
  features/
    units/                      # mis unidades + garantía (cliente)
    tickets/                    # crear/seguir/chat (cliente) y cola/atender (técnico)
    metrics/                    # dashboard técnico
    devices/                    # registro token push
  shared/
    widgets/  models/  utils/
```

State management sugerido: **Riverpod** (o Bloc si el equipo lo prefiere). Routing: **go_router**.

---

## 4. Paquetes recomendados

```yaml
dependencies:
  dio: ^5                       # HTTP
  flutter_riverpod: ^2          # estado
  go_router: ^14               # navegación
  flutter_secure_storage: ^9    # tokens JWT
  firebase_core: ^3             # FCM
  firebase_messaging: ^15       # push
  flutter_local_notifications: ^17  # mostrar push en foreground
  json_annotation: ^4
  image_picker: ^1              # adjuntar fotos a tickets / comprobante
  cached_network_image: ^3
  intl: ^0.19                   # fechas/garantías

dev_dependencies:
  build_runner: ^2
  json_serializable: ^6
  mocktail: ^1                  # tests
```

---

## 5. Generar el cliente de API desde el contrato

Copia `support-api.yaml` al repo de la app (o referencia por ruta) y genera:

```bash
npx @openapitools/openapi-generator-cli generate \
  -i support-api.yaml \
  -g dart-dio \
  -o lib/api \
  --additional-properties=pubName=mastercolor_api,nullableFields=true
```

> Recomendación: trata `lib/api/` como generado (no editarlo). Re-genera cuando cambie el
> contrato. El envelope `{success,data,errors}` puede envolverse: define un
> `ApiResponse<T>` propio en `core/network` que extraiga `data` y mapee `errors`.

---

## 6. Capa de auth (JWT + refresh)

1. **Login** según rol → guarda `access_token` + `role` en `flutter_secure_storage`.
2. **Interceptor de request**: añade `Authorization: Bearer <token>`.
3. **Interceptor de respuesta 401**: intenta `refresh` (endpoint según rol); si falla,
   limpia sesión y redirige a login. Encolar requests durante el refresh para no duplicarlo.
4. **expires_in**: opcionalmente refrescar proactivamente antes de expirar.

```dart
// pseudocódigo del interceptor
onError(err, handler) async {
  if (err.response?.statusCode == 401 && !_isRefreshing) {
    final ok = await authRepository.refresh();           // /client/auth/refresh o /auth/refresh
    if (ok) return handler.resolve(await _retry(err.requestOptions));
    await authRepository.logoutLocal();
    router.go('/login');
  }
  return handler.next(err);
}
```

---

## 7. Push notifications (FCM)

**Setup**: crear proyecto Firebase, añadir apps Android/iOS, `flutterfire configure`.

**Flujo**:
1. Tras login, pedir permiso y obtener el token FCM (`FirebaseMessaging.instance.getToken()`).
2. Registrarlo en el backend:
   - Cliente: `POST /client/devices` `{ "token": "...", "platform": "android" }`
   - Staff: `POST /support/devices`
3. Escuchar `onTokenRefresh` → re-registrar.
4. En **logout**: `DELETE /client/devices/{token}` (o `/support/devices/{token}`).

**Payload `data` que envía el backend** (úsalo para deep-link al ticket):
```json
{ "ticket_id": "123", "type": "ticket_status | ticket_message | ticket_assigned" }
```
- `ticket_status`: navegar al detalle del ticket (cambió de estado).
- `ticket_message`: abrir el chat del ticket (nueva respuesta).
- `ticket_assigned`: (técnico) abrir el ticket recién asignado.

En foreground, mostrar con `flutter_local_notifications`; en background/terminated, manejar
el tap con `onMessageOpenedApp` / `getInitialMessage`.

> El backend omite el push con gracia si no está `FCM_SERVER_KEY` configurado; coordina con
> backend para tenerlo en el entorno de pruebas.

---

## 8. Subida de archivos (multipart)

- **Comprobante de unidad** (registro manual): `POST /client/units` con campo `proof_file`
  (imagen jpg/jpeg/png/webp, máx 5MB).
- **Adjuntos de ticket**: `POST /client/support/tickets/{id}/attachments` con `files[]`
  (hasta 5 imágenes por petición) y `message_id` opcional.

Usa `image_picker` + `dio` `FormData.fromMap({ 'files[]': [MultipartFile...] })`. Comprimir
en cliente antes de subir.

---

## 9. Checklist de features

### Sprint 4 — App cliente (MVP)
- [ ] Login/registro cliente + verificación de email + recuperación
- [ ] Mis unidades (`GET /client/units`) y detalle (`/{id}`)
- [ ] Estado de garantía (`/{id}/warranty`) con badge vigente/vencida
- [ ] Registrar unidad manual con comprobante (`POST /client/units`)
- [ ] Crear ticket (`POST /client/support/tickets`) con categoría/prioridad y unidad opcional
- [ ] Lista y detalle de tickets (`GET .../tickets`, `/{id}`) con timeline de estados
- [ ] Chat del ticket (`POST .../messages`) + adjuntos (`.../attachments`)
- [ ] Calificar (`POST .../rate`) y reabrir (`PUT .../reopen`)
- [ ] Registro de token push + manejo de notificaciones

### Sprint 5 — Módulo técnico
- [ ] Login staff + gating por rol
- [ ] Cola de tickets con filtros (`GET /support/tickets?status=&priority=&...`)
- [ ] Mis asignados (`GET /support/tickets/mine`)
- [ ] Detalle con notas internas (`GET /support/tickets/{id}`)
- [ ] Asignar (`PATCH .../assign`), cambiar estado (`PATCH .../status`)
- [ ] Responder / nota interna (`POST .../messages` con `is_internal`)
- [ ] Diagnóstico + resolver (`POST .../diagnosis`)
- [ ] Asignar nº de serie a unidad (`PATCH /support/units/{id}`)
- [ ] Dashboard de métricas (`GET /support/metrics`)

---

## 10. Configuración por entorno (flavors)

Define `dev` / `prod` con su `baseUrl`:
```dart
class Env {
  static const baseUrl = String.fromEnvironment('API_BASE_URL',
      defaultValue: 'http://localhost:8000/api');
}
```
```bash
flutter run --dart-define=API_BASE_URL=https://api.mastercolor.example/api
```

> En Android emulador, `localhost` del host es `10.0.2.2`.

---

## 11. Testing

- **Unit**: repos/controladores con `mocktail` mockeando `ApiClient`.
- **Widget**: pantallas clave (crear ticket, detalle, login).
- **Golden** (opcional) para componentes de estado/garantía.
- Mockea el envelope `{success,data,errors}` en los tests de red.

---

## 12. Primeros pasos sugeridos (orden)

1. `flutter create` + paquetes + estructura de carpetas.
2. `core/network` (Dio + envelope + interceptores) y `core/storage`.
3. Generar `lib/api` desde el OpenAPI.
4. Auth (login cliente) end-to-end contra el backend local.
5. "Mis unidades" + detalle/garantía (primer flujo completo de lectura).
6. Crear/seguir ticket (primer flujo de escritura + adjuntos).
7. Push FCM (registro de token + deep-link).
8. Gating de rol + módulo técnico.

> Cualquier cambio de forma de request/response: actualizar **primero** el OpenAPI en el
> backend, regenerar `lib/api`, y luego ajustar la app.
