# Replicar el entorno de Master Color API en otra PC (con otra cuenta de Cloudflare)

Guía completa para levantar este backend desde cero en una máquina nueva y exponerlo
públicamente mediante un **Cloudflare Tunnel propio** (cuenta y dominio distintos a los
actuales).

Entorno de referencia (la PC actual):

| Componente | Valor actual |
|---|---|
| Ruta del proyecto | `/home/maeldev/Code/appMasterColor/master_color_api` |
| Framework | Laravel 12 (PHP 8.3, `^8.2` mínimo) |
| Base de datos | PostgreSQL 18 nativo del host, BD `mastercolordb`, usuario `mael`, puerto `5432` |
| Servidor HTTP | `php artisan serve --host=0.0.0.0 --port=8000` bajo systemd de usuario |
| Exposición pública | `cloudflared` tunnel `mastercolor-api` → `https://mc-api.djasoft.net.pe` → `http://localhost:8000` |
| Almacenamiento | AWS S3 (`s3-mastercolorfiles`, `us-east-1`) vía `FILESYSTEM_DISK=s3` |
| Cola | `QUEUE_CONNECTION=database` |
| Push | FCM HTTP v1 (`storage/app/firebase/service-account.json`) |
| IA chatbot | OpenRouter (con Ollama local `qwen2.5:3b` como alternativa) |
| Email | Resend |
| Pagos | MercadoPago (producción, con modo simulación activable) |
| Facturación | APIsPerú |

> ⚠️ **Ojo con los secretos.** El `.env`, las credenciales de Firebase y los `.json` de
> `~/.cloudflared/` **no están en git** (y no deben estarlo). Hay que transportarlos aparte,
> por un canal seguro.
>
> 📦 **Ya está preparado:** el respaldo de la base de datos y las claves están empaquetados
> en `/home/maeldev/Code/appMasterColor/mastercolor-migracion-2026-08-30.tar.gz`, fuera del
> repositorio, listo para USB. Ver [paso 3](#3-base-de-datos-postgresql) para restaurar y
> [paso 8](#8-paquete-de-migración-por-usb) para el contenido y su manejo seguro.

---

## Orden de despliegue

Los pasos están pensados para ejecutarse en este orden; cada uno depende del anterior.

| # | Paso | Resultado |
|---|---|---|
| 1 | [Requisitos del sistema](#1-requisitos-del-sistema) | PHP 8.3, Composer, Postgres y `cloudflared` instalados |
| 2 | [Clonar el proyecto](#2-clonar-el-proyecto) | Código + `vendor/` en `~/Code/appMasterColor/master_color_api` |
| 3 | [Base de datos](#3-base-de-datos-postgresql) | `mastercolordb` restaurada desde el respaldo |
| 4 | [Archivo `.env`](#4-archivo-env) | Credenciales en su sitio, `APP_KEY` y `JWT_SECRET` definidos |
| 5 | [Inicializar la aplicación](#5-inicializar-la-aplicación) | La API responde en `localhost:8000` |
| 6 | [Cloudflare Tunnel](#6-cloudflare-tunnel-con-la-cuenta-nueva) | Dominio público + servicios systemd (API, túnel, colas) |
| 7 | [Servicios externos](#7-actualizar-los-servicios-externos-con-el-nuevo-dominio) | MercadoPago, frontend y app apuntando a la URL nueva |
| 8 | [Paquete USB](#8-paquete-de-migración-por-usb) | Transversal: qué llevas contigo y cómo destruirlo después |
| 9 | [Verificación final](#9-verificación-final) | Todo comprobado de punta a punta |

El despliegue en sí (arranque permanente) son los servicios systemd del paso 6:
`mastercolor-api` (Laravel), `mastercolor-tunnel` (Cloudflare) y `mastercolor-queue`
(colas), más el cron del scheduler. No hay Nginx ni PHP-FPM en esta instalación: se sirve
con `artisan serve` detrás del túnel. Para la variante con Docker, ver el anexo final.

---

## 1. Requisitos del sistema

Probado sobre Ubuntu 22.04 / Pop!_OS. Ajusta a tu distro.

```bash
# PHP 8.3 + extensiones que exige el proyecto
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y \
  php8.3-cli php8.3-fpm php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl php8.3-soap php8.3-opcache

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 20 LTS (solo para assets Vite; la API no lo necesita en runtime)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# PostgreSQL 17/18
sudo apt install -y postgresql postgresql-contrib

# Utilidades
sudo apt install -y git unzip jq
```

Extensiones PHP obligatorias (verifica con `php -m`):
`pdo_pgsql, pgsql, mbstring, xml, curl, zip, gd, bcmath, intl, exif, pcntl, openssl, soap`.

- `gd` + `exif` → miniaturas e imágenes de producto.
- `soap` → facturación electrónica (APIsPerú).
- `zip` → exportaciones de Excel (`maatwebsite/excel`).
- `pcntl` → `queue:work`.

**Cloudflared** (necesario para el túnel):

```bash
curl -L -o cloudflared.deb https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb
sudo dpkg -i cloudflared.deb
cloudflared --version
```

---

## 2. Clonar el proyecto

```bash
# La ruta importa: aparece en las unidades systemd del paso 6.
mkdir -p ~/Code/appMasterColor && cd ~/Code/appMasterColor

git clone https://github.com/GiancarloGO/master_color_api.git
cd master_color_api
git checkout main          # rama de trabajo del proyecto
```

### Dependencias PHP

```bash
# Producción (más rápido, sin herramientas de desarrollo)
composer install --no-dev --optimize-autoloader

# Desarrollo (incluye Pest, Pint, IDE helper, Collision)
# composer install
```

Si `composer install` se queja de extensiones faltantes, vuelve al paso 1: te falta algún
`php8.3-*`. Con `--no-dev` no podrás correr `php artisan test` ni `pint`.

### Assets de frontend (opcional)

La API no los necesita. `resources/views/welcome.blade.php` comprueba si existe
`public/build/manifest.json` y, si no está, usa CSS embebido — así que `GET /` responde
igual. Compílalos solo si quieres la portada con los assets propios:

```bash
npm ci
npm run build          # genera public/build/
```

La documentación Swagger (`/api/documentation` → `public/swagger-ui.html`) ya viene en el
repo y no depende de Vite.

### Verificación del clonado

```bash
php artisan --version           # debe imprimir Laravel 12.x
ls vendor/autoload.php          # debe existir
```

Todavía fallará cualquier comando que toque la BD: falta el `.env` (paso 4) y la base
(paso 3).

---

## 3. Base de datos PostgreSQL

El proyecto usa **Postgres nativo del host** (no el de Docker). El `docker-compose.yml`
apunta a `host.docker.internal:5432` justamente por eso.

```bash
sudo systemctl enable --now postgresql

# Crear usuario y base (usa tu propia contraseña, no la de la PC vieja)
sudo -u postgres psql <<'SQL'
CREATE USER mael WITH PASSWORD 'TU_PASSWORD_NUEVA';
CREATE DATABASE mastercolordb OWNER mael;
ALTER USER mael CREATEDB;   -- necesario para la BD de tests
SQL

# Verificar
PGPASSWORD='TU_PASSWORD_NUEVA' psql -h 127.0.0.1 -U mael -d mastercolordb -c 'SELECT 1;'
```

Si `psql` falla con *peer authentication*, edita `/etc/postgresql/*/main/pg_hba.conf`
y asegúrate de tener `host all all 127.0.0.1/32 scram-sha-256`, luego
`sudo systemctl restart postgresql`.

### Restaurar el respaldo de la BD

**Ya existe un respaldo generado**, fuera del repositorio y fuera de git:

```
/home/maeldev/Code/appMasterColor/migracion-mastercolor/
├── LEEME.txt                        # resumen de pasos
├── bd/
│   ├── mastercolordb.dump           # respaldo completo (pg_dump -Fc, 35 tablas con datos)
│   └── mastercolordb-esquema.sql    # solo estructura, para inspección
└── claves/
    ├── env-produccion.txt           # el .env completo
    └── firebase-service-account.json
```

Y comprimido para llevarlo en USB:

```
/home/maeldev/Code/appMasterColor/mastercolor-migracion-2026-08-30.tar.gz   (66 KB)
```

#### Paso 1 — Copiar el paquete a la PC destino

```bash
# desde el USB montado
cp /media/$USER/<USB>/mastercolor-migracion-2026-08-30.tar.gz ~/
cd ~ && tar -xzf mastercolor-migracion-2026-08-30.tar.gz
cd migracion-mastercolor && ls -R
```

#### Paso 2 — Crear el rol y la base vacía

La restauración **no** crea el usuario ni la base: hay que tenerlos listos antes.

```bash
sudo -u postgres psql <<'SQL'
CREATE USER mael WITH PASSWORD 'LA_MISMA_DEL_ENV' CREATEDB;
CREATE DATABASE mastercolordb OWNER mael;
SQL
```

> Usa la contraseña que trae `claves/env-produccion.txt` en `DB_PASSWORD`. Si prefieres
> una nueva, cámbiala en los dos sitios (Postgres y el `.env`) o Laravel no conectará.

#### Paso 3 — Restaurar

```bash
pg_restore -h 127.0.0.1 -U mael -d mastercolordb \
           --no-owner --role=mael --clean --if-exists \
           bd/mastercolordb.dump
```

Qué hace cada flag:

| Flag | Para qué |
|---|---|
| `--no-owner` | Ignora el propietario original de cada objeto; evita errores si el rol difiere |
| `--role=mael` | Ejecuta la restauración asumiendo ese rol |
| `--clean --if-exists` | Borra objetos previos antes de recrearlos: permite reintentar sin recrear la BD |

Es normal ver avisos `DROP ... does not exist` en una base vacía — con `--if-exists` son
inocuos. Lo que **no** es normal es un error de permisos o de `role does not exist`: eso
significa que el paso 2 no se completó.

#### Paso 4 — Verificar la restauración

```bash
# Las 39 migraciones deben aparecer como "Ran"
php artisan migrate:status

# Conteo de filas por tabla
PGPASSWORD='LA_DEL_ENV' psql -h 127.0.0.1 -U mael -d mastercolordb -c \
  "SELECT relname, n_live_tup FROM pg_stat_user_tables
   WHERE n_live_tup > 0 ORDER BY n_live_tup DESC LIMIT 10;"
```

Referencia de lo que había al generar el respaldo (2026-08-30):

| Tabla | Filas |
|---|---|
| `audit_logs` | 265 |
| `details_movements` | 64 |
| `payments` | 55 |
| `order_details` | 55 |
| `chat_logs` | 49 |
| `migrations` | 39 |
| `orders` | 38 |
| `products` | 27 |
| `stocks` | 27 |
| `stock_movements` | 26 |
| `sold_units` | 19 |

Si los números cuadran, la migración de datos está completa. Después de restaurar,
**no ejecutes `migrate --seed`**: los seeders duplicarían registros.

#### Regenerar el respaldo (si pasan días antes de migrar)

El dump es una foto del momento. Para rehacerlo desde la PC actual:

```bash
cd /home/maeldev/Code/appMasterColor/master_color_api
PW=$(grep -E '^DB_PASSWORD' .env | cut -d= -f2- | tr -d '"')
PGPASSWORD="$PW" pg_dump -h 127.0.0.1 -U mael -Fc \
  -f /home/maeldev/Code/appMasterColor/migracion-mastercolor/bd/mastercolordb.dump \
  mastercolordb
cd /home/maeldev/Code/appMasterColor
tar -czf mastercolor-migracion-$(date +%F).tar.gz migracion-mastercolor/
```

Conviene detener la API mientras se genera (`systemctl --user stop mastercolor-api`) para
que no entren escrituras a medio dump.

#### Partir de cero

Si no quieres los datos actuales, ignora el dump y usa `php artisan migrate --seed`
en el paso 5.

---

## 4. Archivo `.env`

**La vía rápida** es usar el `.env` que ya viene en el paquete USB, sin rellenar nada a
mano:

```bash
cp ~/migracion-mastercolor/claves/env-produccion.txt .env
chmod 600 .env
# y ajustar solo APP_URL con el dominio del túnel nuevo (paso 6)
```

Si prefieres armarlo desde cero, copia `.env.example` y complétalo. Plantilla con los
valores reales del entorno actual (los secretos van marcados; ver paso 8):

```dotenv
APP_NAME="Master Color"
APP_ENV=production            # usa "local" si es una PC de desarrollo
APP_KEY=                      # se genera con artisan (ver abajo)
APP_DEBUG=false
APP_LOCALE=es
APP_FALLBACK_LOCALE=es
APP_FAKER_LOCALE=es_PE
APP_MAINTENANCE_DRIVER=file

# 👇 CAMBIAR: dominio del NUEVO túnel Cloudflare (paso 6)
APP_URL="https://mc-api.TU-DOMINIO.com/api/"
APP_FRONTEND_URL="https://mastercolor.net.pe/"
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:3000,https://mastercolor.net.pe,https://www.mastercolor.net.pe

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug
LOG_DEPRECATIONS_CHANNEL=null

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=mastercolordb
DB_USERNAME=mael
DB_PASSWORD=TU_PASSWORD_NUEVA

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=s3
BCRYPT_ROUNDS=12
PHP_CLI_SERVER_WORKERS=4

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# Correo (Resend)
MAIL_MAILER=resend
RESEND_API_KEY=<<SECRETO>>
MAIL_FROM_ADDRESS="noreply@mastercolor.net.pe"
MAIL_FROM_NAME="Master Color"
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=<<SECRETO>>
MAIL_PASSWORD=<<SECRETO>>
MAIL_TIMEOUT=30

# Almacenamiento S3
AWS_ACCESS_KEY_ID=<<SECRETO>>
AWS_SECRET_ACCESS_KEY=<<SECRETO>>
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=s3-mastercolorfiles
AWS_USE_PATH_STYLE_ENDPOINT=false

# JWT
JWT_SECRET=                   # se genera con artisan (ver abajo)

# MercadoPago
MERCADOPAGO_ACCESS_TOKEN=<<SECRETO>>
MERCADOPAGO_PUBLIC_KEY=<<SECRETO>>
MERCADOPAGO_SANDBOX=false
MERCADOPAGO_ALLOW_SIMULATION=true

# Facturación electrónica
APISPERU_TOKEN=<<SECRETO>>

# Push FCM HTTP v1
FCM_PROJECT_ID=appmastercolor
# FCM_CREDENTIALS_PATH=       # si la defines, NO la dejes vacía

# Chatbot IA
AI_PROVIDER=openrouter        # o "ollama" para modelo local
OPENROUTER_API_KEY=<<SECRETO>>
OPENROUTER_MODELS=poolside/laguna-xs.2:free,nvidia/nemotron-3-super-120b-a12b:free
OPENROUTER_TIMEOUT=45
OLLAMA_URL=http://127.0.0.1:11434
OLLAMA_MODEL=qwen2.5:3b
OLLAMA_TIMEOUT=180
CHATBOT_MAX_PRODUCTS=50
CHATBOT_PERSIST_LOGS=false
AUDIT_LOG_RETENTION_DAYS=180
```

Generar las claves de la instancia (**no reutilices las de la PC vieja** salvo que
restaures un dump con sesiones/tokens que quieras conservar):

```bash
php artisan key:generate
php artisan jwt:secret
```

> Si restauras el dump de la BD y quieres que los JWT emitidos sigan siendo válidos,
> copia `APP_KEY` y `JWT_SECRET` originales en vez de regenerarlos.

---

## 5. Inicializar la aplicación

```bash
php artisan storage:link

# Si restauraste el dump del paso 3, la BD ya está migrada: solo comprueba el estado.
php artisan migrate:status
# Si partiste de BD vacía:
# php artisan migrate --seed

php artisan config:clear && php artisan cache:clear

# Credenciales de Firebase para push (vienen en claves/ del paquete USB)
mkdir -p storage/app/firebase
cp ~/migracion-mastercolor/claves/firebase-service-account.json \
   storage/app/firebase/service-account.json
chmod 600 storage/app/firebase/service-account.json .env

# Permisos de escritura
chmod -R 775 storage bootstrap/cache
```

Seeders disponibles: `ProductsWithStockSeeder`, `TecnicoRoleSeeder`,
`TecnicosDePruebaSeeder`, `TestClientSeeder` (los dos últimos, para el modo simulación
de pagos).

### Optimización para producción

Solo si `APP_ENV=production`. Es lo mismo que hace `docker/start.sh` al arrancar el
contenedor:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> ⚠️ Con `config:cache` activo, Laravel **deja de leer el `.env`**: cualquier cambio
> posterior (por ejemplo el `APP_URL` del túnel nuevo) exige volver a ejecutar
> `php artisan config:cache`, o el cambio no surte efecto. Para revertir:
> `php artisan optimize:clear`.

En una PC de desarrollo (`APP_ENV=local`) **no cachees**: complica el día a día.

### Prueba local antes de exponer nada

```bash
php artisan serve --port=8000

# En otra terminal
curl -i http://localhost:8000/api/          # la API responde
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/    # portada: 200
```

Si esto no funciona, no sigas al paso 6: el túnel solo reenvía tráfico, no arregla una
app que no arranca.

---

## 6. Cloudflare Tunnel con la cuenta nueva

Aquí está la parte específica del cambio de cuenta. El túnel actual (`mastercolor-api`,
UUID `32cd2e35-…`) pertenece a la cuenta vieja y **no se puede migrar**: hay que crear
uno nuevo. Los `.json` de `~/.cloudflared/` de la PC vieja no sirven en la cuenta nueva.

### 6.1 Prerrequisito

El dominio que vas a usar debe estar **agregado como zona en la cuenta nueva de
Cloudflare**, con sus nameservers apuntando a Cloudflare y estado *Active*.

### 6.2 Autenticar cloudflared y descargar el certificado de la cuenta nueva

Este es **el paso clave del cambio de cuenta**. El archivo `~/.cloudflared/cert.pem` es
un certificado de origen que Cloudflare emite *para una cuenta y una zona concretas*. El
que existe hoy pertenece a la cuenta antigua y **no sirve** para crear túneles en la
cuenta nueva: hay que descargar uno nuevo iniciando sesión con esa cuenta.

#### a) Apartar el certificado anterior

Si la PC destino nunca tuvo `cloudflared`, sáltate esto. Si ya lo tenía (o copiaste por
error el `~/.cloudflared` de la PC vieja):

```bash
ls -la ~/.cloudflared/

# Respaldar en vez de borrar, por si hay que volver atrás
mv ~/.cloudflared/cert.pem ~/.cloudflared/cert.pem.cuenta-anterior
```

Los `.json` (`<UUID>.json`) de la cuenta vieja tampoco sirven; puedes moverlos a un
subdirectorio o borrarlos. Lo único que necesitas conservar son los `.yml` como
referencia.

#### b) Cerrar sesión de Cloudflare en el navegador

`cloudflared tunnel login` abre el navegador **predeterminado** y usa la sesión que ya
esté activa. Si tienes abierta la cuenta antigua, el certificado saldrá otra vez de esa
cuenta. Antes de continuar:

- Entra a `https://dash.cloudflare.com` y cierra sesión, **o**
- abre una ventana de incógnito y déjala lista, **o**
- ten a mano las credenciales de la cuenta nueva para cambiar de usuario.

#### c) Ejecutar el login

```bash
cloudflared tunnel login
```

Verás algo así:

```
Please open the following URL and log in with your Cloudflare account:

https://dash.cloudflare.com/argotunnel?aud=&callback=https%3A%2F%2Flogin.cloudflareaccess.org%2F...

Leave cloudflared running to download the cert automatically.
```

En el navegador:

1. **Inicia sesión con la cuenta NUEVA de Cloudflare.**
2. Cloudflare muestra la lista de zonas (dominios) de esa cuenta. Selecciona el dominio
   que vas a usar para la API — por ejemplo `TU-DOMINIO.com`.
3. Pulsa **Authorize**.

La terminal, que quedó esperando, confirma la descarga:

```
You have successfully logged in.
If you wish to copy your credentials to a server, they have been saved to:
/home/<TU_USUARIO>/.cloudflared/cert.pem
```

#### d) Si la PC no tiene navegador (servidor headless)

Copia la URL que imprime el comando, ábrela en el navegador de **otra** máquina,
autoriza el dominio y luego descarga el `cert.pem` desde ese equipo. Otra opción es
hacer el `login` en tu portátil y transferir solo ese archivo:

```bash
scp ~/.cloudflared/cert.pem usuario@pc-destino:~/.cloudflared/cert.pem
chmod 600 ~/.cloudflared/cert.pem
```

El `cert.pem` es portable entre máquinas mientras sea de la misma cuenta y zona.

#### e) Verificar que quedó ligado a la cuenta correcta

```bash
ls -l ~/.cloudflared/cert.pem

# Debe listar los túneles de la cuenta NUEVA (al principio, ninguno)
cloudflared tunnel list
```

Si `tunnel list` devuelve los túneles viejos (`almazen-djasoft`, `mozaico-api`,
`mastercolor-api`…), el certificado sigue siendo el de la cuenta anterior: repite desde
el punto (a) cerrando bien la sesión del navegador.

Un `Error: Cannot determine default origin certificate path` significa que no hay
`cert.pem`; un `Unauthorized: Failed to get tunnel` significa que el que hay es de otra
cuenta.

> **Múltiples cuentas en la misma PC:** puedes guardar certificados separados y elegir
> cuál usar con la variable `TUNNEL_ORIGIN_CERT` o el flag `--origincert`:
> ```bash
> cloudflared tunnel --origincert ~/.cloudflared/cert-nueva.pem list
> export TUNNEL_ORIGIN_CERT=~/.cloudflared/cert-nueva.pem
> ```
> Útil si esa máquina también atiende túneles de la cuenta antigua.

### 6.3 Crear el túnel

```bash
cloudflared tunnel create mastercolor-api
```

Salida: un UUID nuevo y el archivo `~/.cloudflared/<UUID>.json` (las credenciales del
túnel). Guarda el UUID.

```bash
cloudflared tunnel list      # confirma que aparece
```

### 6.4 Archivo de configuración

Crea `~/.cloudflared/mastercolor-api.yml` (réplica del actual, con tus valores):

```yaml
tunnel: <UUID-NUEVO>
credentials-file: /home/<TU_USUARIO>/.cloudflared/<UUID-NUEVO>.json

ingress:
  - hostname: mc-api.TU-DOMINIO.com
    service: http://localhost:8000
    originRequest:
      connectTimeout: 30s
      tcpKeepAlive: 30s
      httpHostHeader: mc-api.TU-DOMINIO.com
      proxyConnectTimeout: 30s
  - service: http_status:404
```

`httpHostHeader` importa: `artisan serve` y la generación de URLs de Laravel dependen
del `Host` correcto.

### 6.5 Crear el registro DNS

```bash
cloudflared tunnel route dns mastercolor-api mc-api.TU-DOMINIO.com
```

Esto crea un CNAME proxied `mc-api → <UUID>.cfargotunnel.com` en la zona. Verifica en el
dashboard de Cloudflare que aparezca con la nube naranja activa.

### 6.6 Prueba manual

```bash
cloudflared tunnel --config ~/.cloudflared/mastercolor-api.yml run
# en otra terminal:
curl -i https://mc-api.TU-DOMINIO.com/api/
```

### 6.7 Servicios systemd (usuario)

Réplica del arranque automático actual. Crea los dos archivos:

`~/.config/systemd/user/mastercolor-api.service`

```ini
[Unit]
Description=MasterColor API - Laravel artisan serve
After=network.target postgresql.service

[Service]
Type=simple
WorkingDirectory=/home/<TU_USUARIO>/Code/appMasterColor/master_color_api
ExecStart=/usr/bin/php artisan serve --host=0.0.0.0 --port=8000
Restart=always
RestartSec=5

[Install]
WantedBy=default.target
```

`~/.config/systemd/user/mastercolor-tunnel.service`

```ini
[Unit]
Description=MasterColor Cloudflare Tunnel
After=network.target mastercolor-api.service
Requires=mastercolor-api.service

[Service]
ExecStart=/usr/local/bin/cloudflared tunnel --config /home/<TU_USUARIO>/.cloudflared/mastercolor-api.yml run
Restart=on-failure
RestartSec=5

[Install]
WantedBy=default.target
```

Activar (con *linger* para que sobrevivan al cierre de sesión):

```bash
loginctl enable-linger $USER
systemctl --user daemon-reload
systemctl --user enable --now mastercolor-api.service
systemctl --user enable --now mastercolor-tunnel.service
systemctl --user status mastercolor-api mastercolor-tunnel --no-pager
```

> En la PC actual existe además `mastercolor-laravel.service`, un duplicado antiguo de
> `mastercolor-api.service`. **No lo repliques**: dos unidades peleando por el puerto 8000
> es la causa de que una quede en `activating` permanente.

### 6.8 Worker de colas

`QUEUE_CONNECTION=database`, así que hace falta un worker para emails, push y jobs
diferidos. Crea `~/.config/systemd/user/mastercolor-queue.service`:

```ini
[Unit]
Description=MasterColor Queue Worker
After=network.target postgresql.service

[Service]
WorkingDirectory=/home/<TU_USUARIO>/Code/appMasterColor/master_color_api
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --timeout=60
Restart=always
RestartSec=5

[Install]
WantedBy=default.target
```

```bash
systemctl --user enable --now mastercolor-queue.service
```

### 6.9 Scheduler (purga de logs de auditoría)

`AUDIT_LOG_RETENTION_DAYS` implica una purga diaria (`logs:prune`). Añade al cron:

```bash
crontab -e
# * * * * * cd /home/<TU_USUARIO>/Code/appMasterColor/master_color_api && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

---

## 7. Actualizar los servicios externos con el nuevo dominio

Cambiar el túnel cambia la URL pública. Hay que reflejarlo en cada integración:

| Servicio | Qué actualizar |
|---|---|
| **MercadoPago** | URL de webhook/notificación IPN → `https://mc-api.TU-DOMINIO.com/api/...`. Revisa `MERCADOPAGO_SETUP.md` para las rutas exactas. |
| **Frontend** (`master_color_frontend`) | `VITE_API_URL` apuntando al nuevo `APP_URL`. |
| **App Flutter de soporte** | Base URL de la API en su configuración. |
| **CORS** | Si el frontend cambia de dominio, añádelo a `CORS_ALLOWED_ORIGINS`. |
| **Firebase / FCM** | No depende del dominio; solo del `service-account.json` y `FCM_PROJECT_ID`. |
| **AWS S3** | **Sin cambios.** Mismo bucket `s3-mastercolorfiles`, mismas llaves. No hay que migrar archivos. |

Tras editar el `.env`:

```bash
php artisan config:clear
php artisan config:cache      # solo si estás en producción con la config cacheada
systemctl --user restart mastercolor-api mastercolor-queue
```

Reiniciar el worker de colas no es opcional: `queue:work` mantiene en memoria la
configuración con la que arrancó y seguiría usando la URL antigua.

---

## 8. Paquete de migración por USB

Todo lo que no está en git (respaldo de BD + secretos) ya viene empaquetado en un solo
archivo, listo para copiar al USB:

```
/home/maeldev/Code/appMasterColor/mastercolor-migracion-2026-08-30.tar.gz   66 KB
```

Contenido:

| Ruta dentro del `.tar.gz` | Qué es |
|---|---|
| `LEEME.txt` | Resumen de los pasos, para leer desde el USB sin el repo |
| `bd/mastercolordb.dump` | Respaldo completo (`pg_dump -Fc`), 35 tablas con datos |
| `bd/mastercolordb-esquema.sql` | Solo estructura, para inspección |
| `claves/env-produccion.txt` | El `.env` completo → renombrar a `.env` en destino |
| `claves/firebase-service-account.json` | Credenciales FCM → `storage/app/firebase/service-account.json` |

Secretos que viajan dentro: `APP_KEY`, `JWT_SECRET`, claves AWS S3, MercadoPago
producción, Resend, APIsPerú, OpenRouter, token de APIsPerú y la contraseña de PostgreSQL.

### Copiar al USB

```bash
cp /home/maeldev/Code/appMasterColor/mastercolor-migracion-2026-08-30.tar.gz /media/$USER/<USB>/
sync
```

### Cifrar antes de moverlo (recomendado)

Un USB con estas claves en claro es, en la práctica, acceso total a la producción: pagos,
correo, facturación y el bucket S3. Si el USB va a salir de tus manos o a viajar, cífralo:

```bash
# Con GPG simétrico — pide una contraseña
gpg -c --cipher-algo AES256 mastercolor-migracion-2026-08-30.tar.gz
# genera mastercolor-migracion-2026-08-30.tar.gz.gpg  -> copia SOLO este al USB

# En la PC destino
gpg -d mastercolor-migracion-2026-08-30.tar.gz.gpg > mastercolor-migracion-2026-08-30.tar.gz
tar -xzf mastercolor-migracion-2026-08-30.tar.gz
```

La contraseña se transmite por un canal distinto al USB (llamada, SMS, en persona).

### Después de la migración

- [ ] Borrar el `.tar.gz` del USB de forma segura (`shred -u archivo` o formatear)
- [ ] Borrar el descomprimido de la PC destino: `rm -rf ~/migracion-mastercolor`
- [ ] Confirmar que la API nueva responde antes de destruir nada
- [ ] Si el USB se extravió en algún momento: rotar **todas** las claves, no solo sospechar

### Lo que NO va en el paquete

- `~/.cloudflared/*.json` y `cert.pem` — son de la cuenta antigua y se regeneran en el
  paso 6 con la cuenta nueva.
- `vendor/` y `node_modules/` — se reconstruyen con `composer install`.
- Archivos de S3 — **no hay que migrarlos**: es el mismo bucket (ver más abajo).

### S3 no cambia

A diferencia del túnel, el almacenamiento **se mantiene igual**: mismo bucket
`s3-mastercolorfiles` en `us-east-1`, mismas llaves AWS (ya incluidas en el `.env` del
paquete). No hay que copiar archivos, ni crear bucket, ni ejecutar `migrar_s3.py`. Las dos
PCs pueden apuntar al mismo bucket a la vez sin conflicto.

Verificación en destino:

```bash
php artisan tinker --execute="dump(count(Storage::disk('s3')->files()));"
```

Si devuelve un número > 0, el acceso al bucket funciona con las llaves migradas.

### Higiene pendiente en el repo

Dos archivos sin trackear del directorio actual contienen **claves AWS en texto plano**:

- `migrar_s3.py` — script de migración entre buckets, con `OLD_*` y `NEW_*` hardcodeados.
- `.env.amazonold` — credenciales del bucket AWS anterior (`mastercolorfiles`, `us-east-2`).

Ninguno está en git (`.env*` está ignorado, pero `migrar_s3.py` **no** lo está: si alguien
hace `git add .` se filtra). Antes de replicar: rota esas llaves en la consola de AWS si ya
no se usan, y no lleves esos archivos a la PC nueva. Si el script sigue siendo útil, muévelo
a leer las claves desde variables de entorno.

---

## 9. Verificación final

```bash
# Servicios arriba
systemctl --user status mastercolor-api mastercolor-tunnel mastercolor-queue --no-pager

# API local
curl -i http://localhost:8000/api/

# API pública a través del túnel
curl -i https://mc-api.TU-DOMINIO.com/api/

# Base de datos
php artisan migrate:status

# Almacenamiento S3
php artisan tinker --execute="dump(Storage::disk('s3')->files()[0] ?? 'bucket vacío');"

# Logs en vivo
tail -f storage/logs/laravel.log
journalctl --user -u mastercolor-tunnel -f
```

---

## 10. Problemas frecuentes

| Síntoma | Causa / solución |
|---|---|
| `connection refused` a Postgres | `sudo systemctl start postgresql@17-main` (o la versión de tu cluster) |
| `pg_restore: role "mael" does not exist` | Falta crear el usuario antes de restaurar (paso 3.2) |
| `pg_restore: permission denied for schema public` | Restaura con `--no-owner --role=mael`, o la BD no es propiedad de `mael` |
| Avisos `DROP ... does not exist` al restaurar | Normal en una BD vacía con `--clean --if-exists`; ignóralos |
| Datos duplicados tras restaurar | Se ejecutó `migrate --seed` sobre el dump. Recrea la BD y restaura de nuevo, sin seeders |
| `SQLSTATE[08006] password authentication failed` | `DB_PASSWORD` del `.env` no coincide con la del rol creado en destino |
| Servicio systemd en `activating` eterno | Dos unidades compitiendo por el puerto 8000. Deja solo `mastercolor-api.service` |
| Túnel conecta pero devuelve 502 | Laravel no está escuchando en `localhost:8000`, o el `service:` del YAML apunta al puerto equivocado |
| `error parsing tunnel ID` | El UUID de `mastercolor-api.yml` no coincide con el `.json` de credenciales |
| DNS no resuelve | Falta `cloudflared tunnel route dns`, o el CNAME está sin proxy (nube gris) |
| `Unauthorized: Failed to get tunnel` | El `cert.pem` es de la cuenta vieja. Borra y repite `cloudflared tunnel login` |
| Push no llega, sin error | Falta `FCM_PROJECT_ID` o el `service-account.json`. `PushNotificationService` omite el envío en silencio |
| `FCM_CREDENTIALS_PATH` vacío rompe el push | `env()` no aplica el default si la clave existe vacía. Comenta la línea en vez de dejarla en blanco |
| Emails no salen | Falta el worker de colas (`mastercolor-queue.service`) |
| CORS bloqueado desde el frontend | Añade el origen a `CORS_ALLOWED_ORIGINS` y ejecuta `php artisan config:clear` |
| Tests de soporte fallando con 404 | Fallo de infraestructura de la suite, preexistente y ajeno a esta guía. Verifica la lógica con `php artisan tinker` |

---

## Anexo: alternativa con Docker

El repo trae `Dockerfile` (PHP 8.3-FPM + Nginx) y `docker-compose.yml` (app + Redis).
Ojo con estos detalles:

- El compose espera **Postgres en el host**, no en un contenedor (`DB_HOST=host.docker.internal`, `DB_PORT=5432`).
- Expone el puerto **8000** del host → el `mastercolor-api.yml` del túnel funciona sin cambios.
- Redis queda publicado en el **6381** del host (interno 6379).
- `docker/supervisord.conf` incluye el worker de colas, pero `docker/start.sh` arranca
  Nginx + PHP-FPM sin supervisord: en esa ruta el worker **no** se levanta solo.

```bash
docker compose up -d --build
docker compose logs -f app
```

Para desarrollo con túnel efímero (sin dominio propio):

```bash
cloudflared tunnel --url http://localhost:8000
```

Da una URL `*.trycloudflare.com` distinta en cada arranque — útil para probar webhooks,
inservible para producción. Los scripts `dev-with-ngrok.sh` y `serve-with-ngrok.sh` hacen
lo equivalente con ngrok, pero tienen rutas viejas hardcodeadas
(`/home/maeldev/Code/master_color_api`, sin el `appMasterColor/` intermedio); actualízalas
si las vas a usar.
