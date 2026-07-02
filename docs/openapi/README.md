# Contratos OpenAPI — MasterColor

Este directorio contiene los contratos OpenAPI del backend:

- **`support-api.yaml`** — API que consume la app móvil Flutter de soporte técnico
  (unidades, garantías, tickets). Contrato de diseño (Sprint 0): es la fuente de
  verdad; primero se acuerda aquí, luego se implementa en el backend Laravel.
- **`catalog-api.yaml`** — API de catálogo administrativo (panel web). Documenta el
  CRUD de **categorías** de productos (`/categories`), protegido por `staffAuth`
  con rol administrador. Implementado en el backend.

## Previsualizar

- **Swagger UI / Redoc online**: pega el contenido en https://editor.swagger.io
- **Redocly local** (requiere Node):
  ```bash
  npx @redocly/cli preview-docs docs/openapi/support-api.yaml
  ```
- **Validar / lint**:
  ```bash
  npx @redocly/cli lint docs/openapi/support-api.yaml
  ```

## Generar el cliente Dart/Flutter

```bash
# openapi-generator (requiere Java)
npx @openapitools/openapi-generator-cli generate \
  -i docs/openapi/support-api.yaml \
  -g dart-dio \
  -o ../master_color_app/lib/api
```

## Convenciones reflejadas en el contrato

- **Envelope** estándar del backend: `{ success, message, status, data, errors }`.
- **Auth**: JWT Bearer. Dos guards:
  - `clientAuth` → guard `client` (login en `/client/auth/login`).
  - `staffAuth` → guard `users` (login en `/auth/login`).
- **Paginación** estilo Laravel (`page`, `per_page`).
- Las **unidades** admiten `serial_number` opcional (soporte mixto serializado / no serializado).
- **Garantías** con `warranty_expires_at` y estado calculado (`vigente` / `vencida`).
- **Máquina de estados** del ticket documentada en `PATCH /support/tickets/{id}/status`.

## Estado

Borrador. Endpoints **aún no implementados** en el backend (ver roadmap Sprint 1+).
Cualquier cambio de forma de request/response debe actualizarse aquí **antes** de implementarse.
